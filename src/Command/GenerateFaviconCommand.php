<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

#[AsCommand(
    name: 'app:assets:generate-favicon',
    description: 'Genera favicon (.ico) e icone PNG (16/32/48/180/192/512) a partire da un PNG in assets/img.'
)]
final class GenerateFaviconCommand extends Command
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'png',
            InputArgument::REQUIRED,
            'Path del PNG dentro assets/img (es: logo.png oppure subdir/logo.png).'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $assetsImgDir = $this->projectDir . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'img';
        $assetsImgDirReal = realpath($assetsImgDir);

        if ($assetsImgDirReal === false) {
            $io->error("Cartella assets/img non trovata: {$assetsImgDir}");
            return Command::FAILURE;
        }

        $rel = (string) $input->getArgument('png');
        $rel = ltrim($rel, "/\\");
        // consenti anche input tipo "assets/img/logo.png"
        $rel = preg_replace('#^assets[\/\\\\]img[\/\\\\]#i', '', $rel) ?? $rel;

        $source = $assetsImgDirReal . DIRECTORY_SEPARATOR . $rel;
        $sourceReal = realpath($source);

        if ($sourceReal === false || !is_file($sourceReal)) {
            $io->error("File PNG non trovato in assets/img: {$rel}");
            return Command::FAILURE;
        }

        if (!$this->isPathInside($sourceReal, $assetsImgDirReal)) {
            $io->error('Path non consentito: il file deve stare dentro assets/img.');
            return Command::FAILURE;
        }

        $ext = strtolower((string) pathinfo($sourceReal, PATHINFO_EXTENSION));
        if ($ext !== 'png') {
            $io->error('Il file deve essere un .png');
            return Command::FAILURE;
        }

        $outputDir = $assetsImgDirReal;
        if (!is_dir($outputDir)) {
            $io->error("Cartella di destinazione non trovata: {$outputDir}");
            return Command::FAILURE;
        }

        $magick = $this->resolveImageMagickBinary();
        if ($magick === null) {
            $io->error("ImageMagick non trovato. Installa ImageMagick e assicurati che il comando `magick` sia nel PATH.");
            return Command::FAILURE;
        }

        $tmpDir = $this->projectDir . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'favicon';
        if (!is_dir($tmpDir) && !@mkdir($tmpDir, 0775, true) && !is_dir($tmpDir)) {
            $io->error("Impossibile creare la cartella temporanea: {$tmpDir}");
            return Command::FAILURE;
        }

        $master = $tmpDir . DIRECTORY_SEPARATOR . 'master.png';

        $io->title('Generazione favicon');
        $io->text('Sorgente: ' . $this->shortPath($sourceReal));
        $io->text('Output: assets/img/');

        // 1) crea un master quadrato 1024x1024 con trasparenza (shrink-only + padding)
        $io->section('Creo master.png (1024x1024) con padding');
        $cmdMaster = [
            $magick,
            $sourceReal,
            '-background', 'none',
            '-alpha', 'on',
            '-resize', '1024x1024>',
            '-gravity', 'center',
            '-extent', '1024x1024',
            $master,
        ];
        if (!$this->runProcess($cmdMaster, $io)) {
            return Command::FAILURE;
        }

        // 2) PNG varie misure
        $io->section('Genero PNG');
        $targets = [
            'favicon-16.png' => '16x16',
            'favicon-32.png' => '32x32',
            'favicon-48.png' => '48x48',
            'apple-touch-icon.png' => '180x180',
            'android-chrome-192.png' => '192x192',
            'android-chrome-512.png' => '512x512',
        ];

        foreach ($targets as $filename => $size) {
            $outFile = $outputDir . DIRECTORY_SEPARATOR . $filename;
            $cmd = [$magick, $master, '-resize', $size, $outFile];

            $io->text(" - {$filename} ({$size})");
            if (!$this->runProcess($cmd, $io)) {
                return Command::FAILURE;
            }
        }

        // 3) favicon.ico multi-size
        $io->section('Genero favicon.ico multi-risoluzione');
        $ico = $outputDir . DIRECTORY_SEPARATOR . 'favicon.ico';
        $cmdIco = [
            $magick,
            $master,
            '-define', 'icon:auto-resize=16,32,48,64,128,256',
            $ico,
        ];
        if (!$this->runProcess($cmdIco, $io)) {
            return Command::FAILURE;
        }

        $io->success('Fatto. File creati in assets/img/: favicon.ico, favicon-16.png, favicon-32.png, favicon-48.png, apple-touch-icon.png, android-chrome-192.png, android-chrome-512.png');
        return Command::SUCCESS;
    }

    private function resolveImageMagickBinary(): ?string
    {
        // Preferisci "magick" (soprattutto su Windows); "convert" su Windows può essere un comando di sistema.
        foreach (['magick', 'convert'] as $bin) {
            $p = new Process([$bin, '-version']);
            $p->setTimeout(5);
            try {
                $p->run();
                if ($p->isSuccessful()) {
                    return $bin;
                }
            } catch (\Throwable) {
                // ignore
            }
        }
        return null;
    }

    private function runProcess(array $cmd, SymfonyStyle $io): bool
    {
        $p = new Process($cmd);
        $p->setTimeout(120);
        $p->run();

        if (!$p->isSuccessful()) {
            $io->error(trim($p->getErrorOutput() ?: $p->getOutput() ?: 'Comando fallito.'));
            return false;
        }

        return true;
    }

    private function isPathInside(string $path, string $dir): bool
    {
        $dir = rtrim($dir, "/\\") . DIRECTORY_SEPARATOR;

        // Windows: confronto case-insensitive
        if (DIRECTORY_SEPARATOR === '\\') {
            return stripos($path, $dir) === 0;
        }

        return str_starts_with($path, $dir);
    }

    private function shortPath(string $abs): string
    {
        return str_replace($this->projectDir . DIRECTORY_SEPARATOR, '', $abs);
    }
}
