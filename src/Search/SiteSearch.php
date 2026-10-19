<?php

namespace App\Search;

use App\Pagination\Pager;
use App\Repository\ChatMessageRepository;
use App\Repository\CommentRepository;
use App\Repository\PhotoRepository;
use App\Repository\QuoteRepository;
use App\Repository\TopicRepository;
use App\Repository\UserRepository;

/**
 * The six kinds of results of the search page (one tab each), how many fit on a page, and the queries behind them:
 * one COUNT per kind for the tab badges, one bounded page query for the open tab.
 */
final class SiteSearch
{
    public const MIN_LENGTH = 2;

    /** Display order = tab order; `empty` is the French line of an empty tab; `perPage` keeps every page small whatever the number of hits. */
    public const TABS = [
        'comments' => ['label' => 'Messages', 'empty' => 'Aucun message.', 'perPage' => 20],
        'topics' => ['label' => 'Sujets', 'empty' => 'Aucun sujet.', 'perPage' => 25],
        'photos' => ['label' => 'Photos', 'empty' => 'Aucune photo.', 'perPage' => 24],
        'chat' => ['label' => 'Chat', 'empty' => 'Aucun message de chat.', 'perPage' => 30],
        'people' => ['label' => 'Membres', 'empty' => 'Aucun membre.', 'perPage' => 24],
        'quotes' => ['label' => 'Citations', 'empty' => 'Aucune citation.', 'perPage' => 20],
    ];

    public function __construct(
        private readonly CommentRepository $comments,
        private readonly TopicRepository $topics,
        private readonly PhotoRepository $photos,
        private readonly ChatMessageRepository $chat,
        private readonly UserRepository $users,
        private readonly QuoteRepository $quotes,
    ) {
    }

    public static function isSearchable(string $term): bool
    {
        return mb_strlen($term) >= self::MIN_LENGTH;
    }

    /** @return array<string, int> hits per tab key */
    public function counts(string $term): array
    {
        return [
            'comments' => $this->comments->countSearch($term),
            'topics' => $this->topics->countSearch($term),
            'photos' => $this->photos->countSearch($term),
            'chat' => $this->chat->countSearch($term),
            'people' => $this->users->countSearch($term),
            'quotes' => $this->quotes->countSearch($term),
        ];
    }

    /** The tab to open: the requested one when it exists, else the first with hits, else the first. */
    public static function chooseTab(?string $requested, array $counts): string
    {
        if (null !== $requested && isset(self::TABS[$requested])) {
            return $requested;
        }

        foreach ($counts as $tab => $count) {
            if ($count > 0) {
                return $tab;
            }
        }

        return array_key_first(self::TABS);
    }

    /** @param array<string, int> $counts */
    public function pagerFor(string $tab, array $counts, int $page): Pager
    {
        return new Pager($counts[$tab], self::TABS[$tab]['perPage'], $page);
    }

    /** @return list<object> the entities of one page of one tab */
    public function page(string $tab, string $term, Pager $pager): array
    {
        return match ($tab) {
            'comments' => $this->comments->search($term, $pager->perPage, $pager->offset()),
            'topics' => $this->topics->search($term, $pager->perPage, $pager->offset()),
            'photos' => $this->photos->search($term, $pager->perPage, $pager->offset()),
            'chat' => $this->chat->search($term, $pager->perPage, $pager->offset()),
            'people' => $this->users->search($term, $pager->perPage, $pager->offset()),
            'quotes' => $this->quotes->search($term, $pager->perPage, $pager->offset()),
        };
    }
}
