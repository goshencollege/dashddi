<?php

namespace App\Service;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Twig\Environment;

/**
 * Builds a searchable index of the user guide by rendering each guide
 * page's content block and extracting its sections (one entry per
 * `<div class="card" id="...">`). The guide templates are the single
 * source of truth, so the index never drifts out of sync with the docs.
 */
class UserGuideSearchService
{
    /**
     * @param array<string, array{route: string, title: string, icon: string}> $pages
     *     Guide templates to index, keyed by template path.
     */
    public function __construct(
        private readonly Environment $twig,
        private readonly CacheInterface $cache,
        private readonly array $pages,
    ) {
    }

    /**
     * @return array<int, array{page: string, pageTitle: string, icon: string, sectionId: string, sectionTitle: string, snippet: string}>
     */
    public function search(string $query, int $limit = 15): array
    {
        $query = trim($query);
        if ($query === '' || mb_strlen($query) < 2) {
            return [];
        }

        $titleMatches = [];
        $textMatches = [];

        foreach ($this->getIndex() as $entry) {
            $titlePos = mb_stripos($entry['sectionTitle'], $query);
            if ($titlePos !== false) {
                $titleMatches[] = $this->toResult($entry, $query, null);
                continue;
            }

            $textPos = mb_stripos($entry['text'], $query);
            if ($textPos !== false) {
                $textMatches[] = $this->toResult($entry, $query, $textPos);
            }
        }

        return array_slice([...$titleMatches, ...$textMatches], 0, $limit);
    }

    /**
     * @return array<int, array{page: string, pageTitle: string, icon: string, sectionId: string, sectionTitle: string, text: string}>
     */
    private function getIndex(): array
    {
        return $this->cache->get('user_guide_search_index', function (ItemInterface $item): array {
            $item->expiresAfter(3600);

            return $this->buildIndex();
        });
    }

    /**
     * @return array<int, array{page: string, pageTitle: string, icon: string, sectionId: string, sectionTitle: string, text: string}>
     */
    private function buildIndex(): array
    {
        $entries = [];

        foreach ($this->pages as $template => $meta) {
            $html = $this->twig->load($template)->renderBlock('content', []);

            $dom = new \DOMDocument();
            libxml_use_internal_errors(true);
            $dom->loadHTML('<?xml encoding="utf-8" ?><div>' . $html . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING);
            libxml_clear_errors();

            $xpath = new \DOMXPath($dom);
            $cards = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " card ")][@id]');

            foreach ($cards as $card) {
                /** @var \DOMElement $card */
                $id = $card->getAttribute('id');
                $header = $xpath->query('./*[contains(concat(" ", normalize-space(@class), " "), " card-header ")]', $card)->item(0);
                $body = $xpath->query('./*[contains(concat(" ", normalize-space(@class), " "), " card-body ")]', $card)->item(0);

                $text = $body ? $this->normalizeText($body->textContent) : '';
                if ($text === '') {
                    continue;
                }

                $title = $header ? $this->normalizeText($header->textContent) : ucfirst(str_replace('-', ' ', $id));

                $entries[] = [
                    'page' => $meta['route'],
                    'pageTitle' => $meta['title'],
                    'icon' => $meta['icon'],
                    'sectionId' => $id,
                    'sectionTitle' => $title,
                    'text' => $text,
                ];
            }
        }

        return $entries;
    }

    /**
     * @param array{page: string, pageTitle: string, icon: string, sectionId: string, sectionTitle: string, text: string} $entry
     * @return array{page: string, pageTitle: string, icon: string, sectionId: string, sectionTitle: string, snippet: string}
     */
    private function toResult(array $entry, string $query, ?int $matchPos): array
    {
        return [
            'page' => $entry['page'],
            'pageTitle' => $entry['pageTitle'],
            'icon' => $entry['icon'],
            'sectionId' => $entry['sectionId'],
            'sectionTitle' => $entry['sectionTitle'],
            'snippet' => $this->buildSnippet($entry['text'], mb_strlen($query), $matchPos),
        ];
    }

    private function buildSnippet(string $text, int $queryLength, ?int $matchPos): string
    {
        if ($matchPos === null) {
            return mb_strlen($text) > 160 ? mb_substr($text, 0, 160) . '…' : $text;
        }

        $radius = 70;
        $start = max(0, $matchPos - $radius);
        $snippet = trim(mb_substr($text, $start, $queryLength + $radius * 2));

        $prefix = $start > 0 ? '…' : '';
        $suffix = ($start + mb_strlen($snippet)) < mb_strlen($text) ? '…' : '';

        return $prefix . $snippet . $suffix;
    }

    private function normalizeText(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
