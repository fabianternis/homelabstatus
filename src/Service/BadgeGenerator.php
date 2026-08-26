<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\ServiceStatus;

class BadgeGenerator
{
    public function generate(string $label, string $statusText, string $statusColor): string
    {
        $label = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
        $statusText = htmlspecialchars($statusText, ENT_QUOTES, 'UTF-8');

        $labelWidth = max(50, strlen($label) * 7 + 12);
        $statusWidth = max(50, strlen($statusText) * 7 + 14);
        $totalWidth = $labelWidth + $statusWidth;

        $labelX = $labelWidth / 2;
        $statusX = $labelWidth + ($statusWidth / 2);

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="{$totalWidth}" height="20" role="img" aria-label="{$label}: {$statusText}">
  <title>{$label}: {$statusText}</title>
  <linearGradient id="s" x2="0" y2="100%">
    <stop offset="0" stop-color="#bbb" stop-opacity=".1"/>
    <stop offset="1" stop-opacity=".1"/>
  </linearGradient>
  <clipPath id="r">
    <rect width="{$totalWidth}" height="20" rx="3" fill="#fff"/>
  </clipPath>
  <g clip-path="url(#r)">
    <rect width="{$labelWidth}" height="20" fill="#24292e"/>
    <rect x="{$labelWidth}" width="{$statusWidth}" height="20" fill="{$statusColor}"/>
    <rect width="{$totalWidth}" height="20" fill="url(#s)"/>
  </g>
  <g fill="#fff" text-anchor="middle" font-family="Verdana,Geneva,DejaVu Sans,sans-serif" text-rendering="geometricPrecision" font-size="110">
    <text aria-hidden="true" x="{$labelX}0" y="150" fill="#010101" fill-opacity=".3" transform="scale(.1)" textLength="{$labelWidth}0">{$label}</text>
    <text x="{$labelX}0" y="140" transform="scale(.1)" fill="#fff" textLength="{$labelWidth}0">{$label}</text>
    <text aria-hidden="true" x="{$statusX}0" y="150" fill="#010101" fill-opacity=".3" transform="scale(.1)" textLength="{$statusWidth}0">{$statusText}</text>
    <text x="{$statusX}0" y="140" transform="scale(.1)" fill="#fff" textLength="{$statusWidth}0">{$statusText}</text>
  </g>
</svg>
SVG;
    }

    public function forStatus(string $label, ServiceStatus $status): string
    {
        return $this->generate($label, $status->label(), $status->color());
    }

    public function forUptime(string $label, float $uptimePercent): string
    {
        $color = '#10b981'; // Green
        if ($uptimePercent < 95.0) {
            $color = '#ef4444'; // Red
        } elseif ($uptimePercent < 99.0) {
            $color = '#f59e0b'; // Amber
        }

        return $this->generate($label, "{$uptimePercent}%", $color);
    }
}
