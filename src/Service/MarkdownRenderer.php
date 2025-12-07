<?php

namespace App\Service;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;

class MarkdownRenderer
{
    private MarkdownConverter $converter;

    public function __construct()
    {
        // Config base (senza chiavi "table" che danno errore)
        $config = [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 100,
        ];

        // 1) Environment con config
        $environment = new Environment($config);

        // 2) Estensioni core CommonMark
        $environment->addExtension(new CommonMarkCoreExtension());

        // 3) Estensione tabelle
        $environment->addExtension(new TableExtension());

        // 4) Converter finale
        $this->converter = new MarkdownConverter($environment);
    }

    public function render(string $markdown): string
    {
        return $this->converter->convert($markdown)->getContent();
    }

    public function renderFile(string $path): string
    {
        if (!is_file($path)) {
            throw new \RuntimeException(sprintf('Markdown file not found: %s', $path));
        }

        $markdown = file_get_contents($path);

        return $this->render($markdown);
    }
}
