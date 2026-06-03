<?php

namespace App\Form;

use App\Entity\BackupSetting;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class BackupSettingType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('destinationType', ChoiceType::class, [
                'label'   => 'Destination Type',
                'choices' => [
                    'Local path (inside container)' => 'local',
                    'CIFS / Windows share'          => 'cifs',
                ],
                'attr' => ['class' => 'form-select'],
            ])
            ->add('localPath', TextType::class, [
                'label'    => 'Local Path',
                'required' => false,
                'attr'     => ['placeholder' => '/var/www/html/var/backups', 'class' => 'form-control font-monospace'],
                'help'     => 'Absolute path inside the container where backups will be written. Leave blank to use <code>var/backups/</code>.',
                'help_html' => true,
            ])
            ->add('cifsServer', TextType::class, [
                'label'    => 'CIFS Server / Share',
                'required' => false,
                'attr'     => ['placeholder' => '//192.168.1.10/backups', 'class' => 'form-control font-monospace'],
            ])
            ->add('cifsUsername', TextType::class, [
                'label'    => 'CIFS Username',
                'required' => false,
                'attr'     => ['autocomplete' => 'off'],
            ])
            ->add('cifsPassword', PasswordType::class, [
                'label'        => 'CIFS Password',
                'required'     => false,
                'always_empty' => false,
                'attr'         => ['autocomplete' => 'new-password'],
                'help'         => 'Leave blank to keep the existing password.',
            ])
            ->add('cifsSubdir', TextType::class, [
                'label'    => 'CIFS Subdirectory',
                'required' => false,
                'attr'     => ['placeholder' => 'backups', 'class' => 'form-control'],
                'help'     => 'Optional subdirectory within the share to store backup files. Allowed characters: letters, numbers, hyphens, underscores, dots, forward slashes.',
            ])
            ->add('decryptFields', CheckboxType::class, [
                'label'    => 'Decrypt encrypted database fields',
                'required' => false,
                'help'     => 'SSH private keys and passwords stored encrypted will be written as plaintext in the backup. Mutually exclusive with "include encryption key".',
            ])
            ->add('includeEncryptionKey', CheckboxType::class, [
                'label'    => 'Include APP_ENCRYPTION_KEY in backup header',
                'required' => false,
                'help'     => 'Adds the encryption key as a SQL comment so the backup can be fully decrypted later even if the key changes. Ignored when "decrypt fields" is enabled.',
            ])
            ->add('encryptBackup', CheckboxType::class, [
                'label'    => 'Encrypt the backup file',
                'required' => false,
                'help'     => 'Uses AES-256-CBC + PBKDF2 to encrypt the backup file. Requires a backup password below.',
            ])
            ->add('backupPassword', PasswordType::class, [
                'label'        => 'Backup Encryption Password',
                'required'     => false,
                'always_empty' => false,
                'attr'         => ['autocomplete' => 'new-password'],
                'help'         => 'Password used to encrypt/decrypt backup files. Leave blank to keep the existing password.',
            ])
            ->add('excludeDhcpLeases', CheckboxType::class, [
                'label'    => 'Exclude DHCP lease log',
                'required' => false,
                'help'     => 'Omits the <code>dhcp_lease</code> table from the backup. Useful for keeping backup files small when lease history is not required for a restore.',
                'help_html' => true,
            ])
            ->add('retentionCount', IntegerType::class, [
                'label' => 'Backups to Keep',
                'attr'  => ['placeholder' => '10', 'min' => '0'],
                'help'  => 'Number of backup files to retain. Older files are deleted automatically. Set to 0 to keep all backups.',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => BackupSetting::class]);
    }
}
