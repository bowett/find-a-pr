<?php

namespace App\Services;

use App\DataTransferObjects\Issue;
use App\DataTransferObjects\IssueOwner;
use App\DataTransferObjects\Label;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class IssueService
{
    private const BASE_URL = 'https://api.github.com/repos/';

    /**
     * @return array<Issue>
     */
    public function getAll(): array
    {
        $issues = [];

        foreach (config('repos.repos') as $repo) {
            $url = self::BASE_URL . $repo['owner'] . '/' . $repo['name'] . '/issues';

            $fetchedIssues = Cache::remember($url, now()->addMinutes(10), static fn () => Http::get($url)->json());

            foreach ($fetchedIssues as $fetchedIssue) {
                if ($this->shouldIncludeIssue($fetchedIssue)) {
                    $issues[] = $this->parseIssue($repo, $fetchedIssue);
                }
            }
        }

        return $issues;
    }

    private function parseIssue(array $repo, array $fetchedIssue): ?Issue
    {
        $repoName = $repo['owner'].'/'.$repo['name'];

        $createdBy = new IssueOwner(
            name: $fetchedIssue['user']['login'],
            url: $fetchedIssue['user']['html_url'],
            profilePictureUrl: $fetchedIssue['user']['avatar_url'],
        );

        $labels = [];

        foreach ($fetchedIssue['labels'] as $label) {
            $labels[] = new Label(
                name: $label['name'],
                color: '#'.$label['color'],
            );
        }

        return new Issue(
            repoName: $repoName,
            repoUrl: 'https://github.com/'.$repoName,
            title: $fetchedIssue['title'],
            url: $fetchedIssue['html_url'],
            body: $fetchedIssue['body'],
            labels: $labels,
            createdAt: Carbon::parse($fetchedIssue['created_at']),
            createdBy: $createdBy,
        );
    }

    private function shouldIncludeIssue(array $fetchedIssue): bool
    {
        return ! $this->issueIsAPullRequest($fetchedIssue)
            && $this->includesAtLeastOneLabel($fetchedIssue, config('repos.labels'));
    }

    private function includesAtLeastOneLabel(array $fetchedIssue, mixed $labels): bool
    {
        $issueLabels = Arr::pluck($fetchedIssue['labels'], 'name');

        return array_intersect($issueLabels, $labels) !== [];
    }

    private function issueIsAPullRequest(array $fetchedIssue): bool
    {
        return isset($fetchedIssue['pull_request']);
    }
}
