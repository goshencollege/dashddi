<?php

namespace App\Controller;

use App\Form\BackupSettingType;
use App\Repository\BackupSettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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
                    'name' => basename($file),
                    'size' => filesize($file),
                    'time' => filemtime($file),
                ];
            }
        }

        return $this->render('backup/index.html.twig', [
            'form'      => $form,
            'backups'   => $backups,
            'localPath' => $localPath,
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
        if ($password !== '') {
            $args[] = '--backup-password=' . $password;
        }

        $process = new Process($args);
        $process->setTimeout(600);
        $process->run();

        @unlink($tmpPath);

        if ($process->isSuccessful()) {
            $this->addFlash('success', 'Database restored successfully. Migrations have been applied.');
        } else {
            $this->addFlash('danger', 'Restore failed: ' . trim($process->getErrorOutput() ?: $process->getOutput()));
        }

        return $this->redirectToRoute('backup_index');
    }
}
