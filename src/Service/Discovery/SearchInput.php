<?php

declare(strict_types=1);

namespace App\Service\Discovery;

use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class SearchInput
{
    public const MODES = ['expanded', 'original'];
    public const LANGUAGES = ['en', 'de'];
    public const KINDS = [
        'TopCollection', 'Collection', 'Resource', 'Person', 'Organisation',
        'Place', 'Publication', 'Project', 'Concept',
    ];
    public const SCOPES = [
        'main' => ['hasTitle', 'hasAlternativeTitle', 'hasDescription', 'hasSubject', 'hasKeyword'],
        'title' => ['hasTitle', 'hasAlternativeTitle'],
        'description' => ['hasDescription'],
        'subject' => ['hasSubject', 'hasKeyword'],
    ];
    public const MAX_PAGE = 1000;
    public const PAGE_SIZE = 20;

    private function __construct(
        public string $query,
        public string $mode,
        public string $kind,
        public string $scope,
        public int $page,
        public string $language,
    ) {
    }

    public static function fromArray(array $input): self
    {
        $query = trim(self::string($input, 'q', ''));
        if (mb_strlen($query, 'UTF-8') < 2) {
            throw new InvalidArgumentException('Enter 2–500 characters.');
        }

        $mode = self::string($input, 'mode', 'expanded');
        $kind = self::string($input, 'kind', '');
        $scope = self::string($input, 'scope', 'main');
        $language = self::language($input);
        $pageValue = array_key_exists('page', $input) ? $input['page'] : 1;
        $page = is_int($pageValue) || is_string($pageValue)
            ? filter_var($pageValue, FILTER_VALIDATE_INT)
            : false;

        if (!in_array($mode, self::MODES, true)) {
            throw new InvalidArgumentException('Unknown search mode.');
        }
        if ($kind !== '' && !in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException('Unknown record type.');
        }
        if (!isset(self::SCOPES[$scope])) {
            throw new InvalidArgumentException('Unknown search scope.');
        }
        if ($page === false || $page < 1 || $page > self::MAX_PAGE) {
            throw new InvalidArgumentException('Page must be between 1 and 1000.');
        }

        return new self($query, $mode, $kind, $scope, $page, $language);
    }

    public static function string(array $input, string $key, string $default): string
    {
        $value = array_key_exists($key, $input) ? $input[$key] : $default;
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > 500) {
            throw new InvalidArgumentException(sprintf('%s must be a UTF-8 string of at most 500 characters.', $key));
        }
        return $value;
    }

    public static function language(array $input): string
    {
        $language = self::string($input, 'lang', 'en');
        if (!in_array($language, self::LANGUAGES, true)) {
            throw new InvalidArgumentException('Language must be en or de.');
        }
        return $language;
    }
}
