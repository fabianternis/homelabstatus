<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Locale\LocaleProvider;
use App\Service\UplinkMonitorService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

class HomeController extends AbstractController
{
    public function __construct(
        private readonly UplinkMonitorService $monitorService,
        private readonly TranslatorInterface $translator,
        private readonly LocaleProvider $localeProvider
    ) {}

    #[Route('/', name: 'home_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $availableLocales = $this->localeProvider->getAvailableLocales();
        $availableCodes = array_keys($availableLocales);

        // Check if a specific language is requested via URL query param
        $requestedLocale = $request->query->get('lang') ?? $request->query->get('_locale');
        $session = $request->hasSession() ? $request->getSession() : null;

        if ($requestedLocale && in_array(strtolower($requestedLocale), $availableCodes, true)) {
            $locale = strtolower($requestedLocale);
            $session?->set('_locale', $locale);
        } elseif ($session && $session->has('_locale') && in_array($session->get('_locale'), $availableCodes, true)) {
            $locale = (string)$session->get('_locale');
        } else {
            $preferred = $request->getPreferredLanguage($availableCodes);
            $locale = $preferred && in_array($preferred, $availableCodes, true) ? $preferred : 'en';
            $session?->set('_locale', $locale);
        }

        $request->setLocale($locale);

        $status = $this->monitorService->getCurrentStatus();
        $history = $this->monitorService->getHistoryWithSparklines(20);

        // Resolve client / gateway IP
        $clientIp = $request->headers->get('CF-Connecting-IP')
            ?: $request->headers->get('X-Forwarded-For')
            ?: $request->getClientIp()
            ?: '127.0.0.1';

        if (str_contains($clientIp, ',')) {
            $clientIp = trim(explode(',', $clientIp)[0]);
        }

        // Pass translated state labels for client-side JS live updater
        $stateTranslations = [
            'excellent' => $this->translator->trans('state.excellent', [], 'messages', $locale),
            'good' => $this->translator->trans('state.good', [], 'messages', $locale),
            'degraded' => $this->translator->trans('state.degraded', [], 'messages', $locale),
            'offline' => $this->translator->trans('state.offline', [], 'messages', $locale),
            'unknown' => $this->translator->trans('state.unknown', [], 'messages', $locale),
        ];

        $uiTranslations = [
            'connecting' => $this->translator->trans('app.connecting', [], 'messages', $locale),
            'live_sse' => $this->translator->trans('app.live_sse', [], 'messages', $locale),
            'fallback_polling' => $this->translator->trans('app.fallback_polling', [], 'messages', $locale),
            'paused' => $this->translator->trans('app.paused', [], 'messages', $locale),
            'idle_background' => $this->translator->trans('app.idle_background', [], 'messages', $locale),
            'pause' => $this->translator->trans('app.pause', [], 'messages', $locale),
            'resume' => $this->translator->trans('app.resume', [], 'messages', $locale),
            'ping_now' => $this->translator->trans('status.ping_now', [], 'messages', $locale),
            'pinging' => $this->translator->trans('status.pinging', [], 'messages', $locale),
            'title_template' => $this->translator->trans('status.title', ['%state%' => '{state}'], 'messages', $locale),
            'summary_template' => $this->translator->trans('status.probed_summary', ['%healthy%' => '{healthy}', '%total%' => '{total}'], 'messages', $locale),
        ];

        return $this->render('home/index.html.twig', [
            'status' => $status,
            'history' => $history,
            'clientIp' => $clientIp,
            'locale' => $locale,
            'availableLocales' => $availableLocales,
            'stateTranslations' => $stateTranslations,
            'uiTranslations' => $uiTranslations,
        ]);
    }
}
