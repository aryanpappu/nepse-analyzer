<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class SiteInformationController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $contact = config('public_site.contact_email');

        return response()->json(['data' => [
            'name' => config('app.name', 'NEPSE Analyzer'),
            'operator_name' => config('public_site.operator_name'),
            'contact_email' => is_string($contact) && filter_var($contact, FILTER_VALIDATE_EMAIL) ? $contact : null,
            'privacy_url' => $this->httpsUrl(config('public_site.privacy_url')),
            'terms_url' => $this->httpsUrl(config('public_site.terms_url')),
        ]]);
    }

    private function httpsUrl(mixed $value): ?string
    {
        return is_string($value) && filter_var($value, FILTER_VALIDATE_URL)
            && parse_url($value, PHP_URL_SCHEME) === 'https' ? $value : null;
    }
}
