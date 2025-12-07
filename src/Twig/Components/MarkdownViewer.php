<?php

namespace App\Twig\Components;

use App\Service\MarkdownRenderer;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsTwigComponent('markdown_viewer')]
final class MarkdownViewer
{
    public ?string $markdown = null;
    public ?string $markdownFile = null;

    public function __construct(
        private MarkdownRenderer $markdownRenderer,
        #[Autowire('%kernel.project_dir%')] private string $projectDir,
    ) {}

    public function content(): string
    {
        if ($this->markdown !== null) {
            return $this->markdownRenderer->render($this->markdown);
        }

        if ($this->markdownFile !== null) {
            $path = $this->markdownFile;

            if (!str_starts_with($path, DIRECTORY_SEPARATOR)) {
                $path = $this->projectDir . '/' . ltrim($path, '/');
            }

            return $this->markdownRenderer->renderFile($path);
        }

        return '<p><em>Nessun contenuto markdown fornito.</em></p>';
    }
}
