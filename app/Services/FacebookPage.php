<?php

namespace App\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

// Posts links to the community's Facebook Page through the Graph API.
// Needs a Page access token with the pages_manage_posts permission.
class FacebookPage
{
    public function __construct(
        private ?string $pageId,
        private ?string $token,
        private string $graphVersion = 'v23.0',
    ) {}

    public function configured(): bool
    {
        return filled($this->pageId) && filled($this->token);
    }

    /**
     * Publishes a link post on the Page and returns the new post's id.
     *
     * @throws RequestException
     */
    public function publishLink(string $url, string $message): string
    {
        return Http::asForm()
            ->timeout(15)
            ->post("https://graph.facebook.com/{$this->graphVersion}/{$this->pageId}/feed", [
                'link' => $url,
                'message' => $message,
                'access_token' => $this->token,
            ])
            ->throw()
            ->json('id');
    }
}
