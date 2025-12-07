<?php

namespace App\Twig\Components;

use App\Service\MarkdownRenderer;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsTwigComponent('markdown_modal')]
final class MarkdownModal
{
    /** ID HTML del dialog */
    public string $id = 'markdown-modal';

    /** Titolo mostrato nella header della modale */
    public string $title = 'Dettagli';

    /**
     * Markdown raw (opzionale). Se valorizzato, ha precedenza su markdownFile.
     */
    public ?string $markdown = null;

    /**
     * Percorso file markdown (opzionale).
     * Può essere:
     *  - assoluto
     *  - relativo alla root del progetto (es: "var/knowledge/ELARA_Analisi_Tecnica.md")
     */
    public ?string $markdownFile = null;

    public function __construct(
        private MarkdownRenderer $markdownRenderer,
        #[Autowire('%kernel.project_dir%')] private string $projectDir,
    ) {}

    /**
     * Restituisce HTML renderizzato dal markdown.
     * Usato direttamente dal template Twig: {{ this.content()|raw }}
     */
    public function content(): string
    {
        // 1) markdown raw passato dal template
        if ($this->markdown !== null) {
            return $this->markdownRenderer->render($this->markdown);
        }

        // 2) markdownFile passato dal template
        if ($this->markdownFile !== null) {
            $path = $this->markdownFile;

            // se non è assoluto, lo considero relativo al project_dir
            if (!str_starts_with($path, DIRECTORY_SEPARATOR)) {
                $path = $this->projectDir . '/' . ltrim($path, '/');
            }

            return $this->markdownRenderer->renderFile($path);
        }

        // 3) fallback
        return '<p><em>Nessun contenuto markdown fornito.</em></p>';
    }
}
