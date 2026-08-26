<?php

declare(strict_types=1);

namespace App\Service\Locale;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Intl\Languages;

class LocaleProvider
{
    public function __construct(
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir
    ) {}

    /**
     * Dynamically discovers all available locales from the translations/ directory
     *
     * @return array<string, array{code: string, name: string, native_name: string}>
     */
    public function getAvailableLocales(): array
    {
        $translationsDir = $this->projectDir . '/translations';
        $locales = ['en' => 'English'];

        if (is_dir($translationsDir)) {
            $files = scandir($translationsDir) ?: [];
            foreach ($files as $file) {
                if (preg_match('/^messages\.([a-zA-Z_-]+)\.(?:yaml|yml|json|php)$/', $file, $matches)) {
                    $code = strtolower($matches[1]);
                    $locales[$code] = $code;
                }
            }
        }

        $result = [];
        foreach (array_keys($locales) as $code) {
            $nativeName = ucfirst($code);
            try {
                if (Languages::exists($code)) {
                    $nativeName = ucfirst(Languages::getName($code, $code));
                }
            } catch (\Throwable) {
                $nativeName = strtoupper($code);
            }

            $result[$code] = [
                'code' => $code,
                'name' => ucfirst($code),
                'native_name' => $nativeName,
            ];
        }

        ksort($result);
        return $result;
    }

    /**
     * @return string[]
     */
    public function getAvailableCodes(): array
    {
        return array_keys($this->getAvailableLocales());
    }
}
