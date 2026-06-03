<?php

namespace App\Controller;

use App\Form\BackupSettingType;
use App\Repository\BackupSettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Process\Process;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/backup', name: 'backup_')]
class BackupController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        BackupSettingRepository $repo,
        EntityManagerInterface $em,
    ): Response {
        $setting    = $repo->getInstance();
        $projectDir = $this->getParameter('kernel.project_dir');
        $form       = $this->createForm(BackupSettingType::class, $setting);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Backup settings saved.');
            return $this->redirectToRoute('backup_index');
        }

        $localPath = $setting->getLocalPath() ?? $projectDir . '/var/backups';
        $backups   = [];
        if (is_dir($localPath)) {
            $files = glob(rtrim($localPath, '/') . '/dashddi_backup_*.sql*') ?: [];
            rsort($files);
            foreach ($files as $file) {
                $backups[] = [
                    'name'      => basename($file),
                    'size'      => filesize($file),
                    'time'      => filemtime($file),
                    'encrypted' => str_ends_with($file, '.sql.enc'),
                ];
            }
        }

        return $this->render('backup/index.html.twig', [
            'form'              => $form,
            'backups'           => $backups,
            'localPath'         => $localPath,
            'hasBackupPassword' => $setting->getBackupPassword() !== null && $setting->getBackupPassword() !== '',
        ]);
    }

    #[Route('/run', name: 'run', methods: ['POST'])]
    public function run(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('backup_run', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirectToRoute('backup_index');
        }

        $consolePath = $this->getParameter('kernel.project_dir') . '/bin/console';
        $process     = new Process(['php', $consolePath, 'app:database:backup']);
        $process->setTimeout(600);
        $process->run();

        if ($process->isSuccessful()) {
            $this->addFlash('success', 'Backup completed successfully.');
        } else {
            $this->addFlash('danger', 'Backup failed: ' . trim($process->getErrorOutput() ?: $process->getOutput()));
        }

        return $this->redirectToRoute('backup_index');
    }

    #[Route('/restore', name: 'restore', methods: ['POST'])]
    public function restore(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('backup_restore', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirectToRoute('backup_index');
        }

        $uploadedFile = $request->files->get('backup_file');
        $password     = trim((string) $request->request->get('backup_password', ''));

        if ($uploadedFile === null) {
            $this->addFlash('danger', 'No backup file uploaded.');
            return $this->redirectToRoute('backup_index');
        }

        $origName = $uploadedFile->getClientOriginalName();
        if (!str_ends_with($origName, '.sql') && !str_ends_with($origName, '.sql.enc')) {
            $this->addFlash('danger', 'Invalid file type. Expected .sql or .sql.enc');
            return $this->redirectToRoute('backup_index');
        }

        $tmpPath = $uploadedFile->move('/tmp', $origName)->getPathname();

        $consolePath = $this->getParameter('kernel.project_dir') . '/bin/console';
        $args        = ['php', $consolePath, 'app:database:restore', $tmpPath, '--no-interaction'];

        $process = new Process($args);
        $process->setTimeout(600);
        if ($password !== '') {
            $process->setEnv(['BACKUP_PASSWORD' => $password]);
        }
        $process->run();

        @unlink($tmpPath);

        if ($process->isSuccessful()) {
            $this->addFlash('success', 'Database restored successfully. Migrations have been applied.');
            $this->flashEmbeddedKey($process->getOutput());
        } else {
            $this->addFlash('danger', 'Restore failed: ' . trim($process->getErrorOutput() ?: $process->getOutput()));
        }

        return $this->redirectToRoute('backup_index');
    }

    #[Route('/restore-local', name: 'restore_local', methods: ['POST'])]
    public function restoreLocal(Request $request, BackupSettingRepository $repo): Response
    {
        if (!$this->isCsrfTokenValid('backup_restore_local', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirectToRoute('backup_index');
        }

        $filename = $request->request->get('filename', '');
        if (!$this->isValidBackupFilename($filename)) {
            $this->addFlash('danger', 'Invalid backup filename.');
            return $this->redirectToRoute('backup_index');
        }

        $setting  = $repo->getInstance();
        $filePath = $this->resolveBackupPath($filename, $setting->getLocalPath());

        if ($filePath === null) {
            $this->addFlash('danger', 'Backup file not found.');
            return $this->redirectToRoute('backup_index');
        }

        $isEncrypted = str_ends_with($filename, '.sql.enc');
        $password    = $setting->getBackupPassword() ?? '';

        if ($isEncrypted && $password === '') {
            $this->addFlash('danger', 'This backup is encrypted but no backup password is saved in Backup Settings.');
            return $this->redirectToRoute('backup_index');
        }

        $consolePath = $this->getParameter('kernel.project_dir') . '/bin/console';
        $args        = ['php', $consolePath, 'app:database:restore', $filePath, '--no-interaction'];

        $process = new Process($args);
        $process->setTimeout(600);
        if ($isEncrypted) {
            $process->setEnv(['BACKUP_PASSWORD' => $password]);
        }
        $process->run();

        if ($process->isSuccessful()) {
            $this->addFlash('success', "Database restored from {$filename}. Migrations have been applied.");
            $this->flashEmbeddedKey($process->getOutput());
        } elseif ($isEncrypted && str_contains($process->getOutput(), 'WRONG_BACKUP_PASSWORD')) {
            $this->addFlash('danger',
                "The saved backup password does not match the password used to encrypt \"{$filename}\". " .
                'This can happen if the password was changed after the backup was created. ' .
                'To restore this backup, download it and use the "Restore from Uploaded File" form where you can enter the original password manually.'
            );
        } else {
            $this->addFlash('danger', 'Restore failed: ' . trim($process->getErrorOutput() ?: $process->getOutput()));
        }

        return $this->redirectToRoute('backup_index');
    }

    #[Route('/download/{filename}', name: 'download', methods: ['GET'])]
    public function download(string $filename, BackupSettingRepository $repo): Response
    {
        if (!$this->isValidBackupFilename($filename)) {
            throw $this->createNotFoundException();
        }

        $setting  = $repo->getInstance();
        $filePath = $this->resolveBackupPath($filename, $setting->getLocalPath());

        if ($filePath === null) {
            throw $this->createNotFoundException();
        }

        $response = new BinaryFileResponse($filePath);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename);
        return $response;
    }

    #[Route('/delete', name: 'delete', methods: ['POST'])]
    public function delete(Request $request, BackupSettingRepository $repo): Response
    {
        if (!$this->isCsrfTokenValid('backup_delete', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirectToRoute('backup_index');
        }

        $filename = $request->request->get('filename', '');
        if (!$this->isValidBackupFilename($filename)) {
            $this->addFlash('danger', 'Invalid backup filename.');
            return $this->redirectToRoute('backup_index');
        }

        $setting  = $repo->getInstance();
        $filePath = $this->resolveBackupPath($filename, $setting->getLocalPath());

        if ($filePath === null) {
            $this->addFlash('danger', 'Backup file not found.');
            return $this->redirectToRoute('backup_index');
        }

        if (@unlink($filePath)) {
            $this->addFlash('success', "Backup deleted: {$filename}");
        } else {
            $this->addFlash('danger', "Could not delete: {$filename}");
        }

        return $this->redirectToRoute('backup_index');
    }

    // -------------------------------------------------------------------------

    /** Parses subprocess output for an embedded key notice and adds a flash if found. */
    private function flashEmbeddedKey(string $processOutput): void
    {
        if (preg_match('/^EMBEDDED_KEY:(\S+)/m', $processOutput, $m)) {
            $this->addFlash('encryption_key_notice', $m[1]);
        }
    }

    private function isValidBackupFilename(string $filename): bool
    {
        return (bool) preg_match('/^dashddi_backup_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.sql(\.enc)?$/', $filename);
    }

    /** Returns the real, validated path to the file, or null if it doesn't exist / escapes the directory. */
    private function resolveBackupPath(string $filename, ?string $configuredPath): ?string
    {
        $dir      = rtrim($configuredPath ?? $this->getParameter('kernel.project_dir') . '/var/backups', '/');
        $filePath = $dir . '/' . $filename;
        $realFile = realpath($filePath);
        $realDir  = realpath($dir);

        if ($realFile === false || $realDir === false || !str_starts_with($realFile, $realDir . '/')) {
            return null;
        }

        return $realFile;
    }
}
