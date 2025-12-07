<?php

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class TableFilter extends AbstractExtension
{
    public function getFilters()
    {
        return [
            new TwigFilter('daisy_table', [$this, 'applyDaisyUI'], ['is_safe' => ['html']]),
        ];
    }

    public function applyDaisyUI(string $html): string
    {
        return preg_replace(
            '/<table>/', 
            '<table class="table table-zebra w-full">', 
            $html
        );
    }
}
