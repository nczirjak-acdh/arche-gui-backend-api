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
    public const DATE_FIELDS = [
        'coverage' => ['hasCoverageStartDate', 'hasCoverageEndDate'],
        'created' => ['hasCreatedStartDate', 'hasCreatedEndDate'],
        'original' => ['hasCreatedStartDateOriginal', 'hasCreatedEndDateOriginal'],
    ];
    public const DEFAULT_DATE_TYPE = 'coverage';
    public const MAX_PAGE = 1000;
    public const PAGE_SIZE = 20;

    private function __construct(
        public string $query,
        public string $mode,
        public string $kind,
        public string $scope,
        public int $page,
        public string $language,
        public string $dateType,
        public ?string $dateFrom,
        public ?string $dateTo,
    ) {
    }

    public static function fromArray(array $input): self
    {
        $query = trim(self::string($input, 'q', ''));
        $dateFrom = self::dateBoundary($input, 'date_from', false);
        $dateTo = self::dateBoundary($input, 'date_to', true);
        $dateType = self::string($input, 'date_type', self::DEFAULT_DATE_TYPE);
        if (!isset(self::DATE_FIELDS[$dateType])) {
            throw new InvalidArgumentException('Unknown date type.');
        }
        if ($query === '' && $dateFrom === null && $dateTo === null) {
            throw new InvalidArgumentException('Enter a search term or provide a date filter.');
        }
        if ($query !== '' && mb_strlen($query, 'UTF-8') < 2) {
            throw new InvalidArgumentException('Enter 2–500 characters.');
        }
        if ($dateFrom !== null && $dateTo !== null) {
            $fromYear = (int) $dateFrom;
            $toYear = (int) $dateTo;
            if ($fromYear > $toYear || ($fromYear === $toYear && substr($dateFrom, -5) > substr($dateTo, -5))) {
                throw new InvalidArgumentException('date_from must be on or before date_to.');
            }
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

        return new self($query, $mode, $kind, $scope, $page, $language, $dateType, $dateFrom, $dateTo);
    }

    private static function dateBoundary(array $input, string $key, bool $endOfYear): ?string
    {
        $value = trim(self::string($input, $key, ''));
        if ($value === '') {
            return null;
        }

        if (preg_match('/^(-?[0-9]{4})(?:-([0-9]{2})-([0-9]{2}))?$/D', $value, $parts)) {
            $month = $parts[2] ?? ($endOfYear ? '12' : '01');
            $day = $parts[3] ?? ($endOfYear ? '31' : '01');
            $year = (int) $parts[1];
            // Map BCE years to an equivalent Gregorian leap-year cycle for checkdate().
            $calendarYear = $year < 0 ? 400 - ((-$year - 1) % 400) : $year;
            if (checkdate((int) $month, (int) $day, $calendarYear)) {
                return $parts[1] . '-' . $month . '-' . $day;
            }
        }

        throw new InvalidArgumentException(sprintf(
            '%s must be a valid date (YYYY-MM-DD) or year (YYYY). Prefix BCE years with a minus sign.',
            $key,
        ));
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
