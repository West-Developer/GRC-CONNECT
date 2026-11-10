<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PrivacyNoticeResource;
use App\Support\Privacy\PrivacyNotice;

/**
 * The current Data Privacy Notice's version and contact. Public: the notice is read before anyone signs in
 * (ADR 0055).
 */
final class PrivacyNoticeController extends Controller
{
    public function __invoke(PrivacyNotice $notice): PrivacyNoticeResource
    {
        return PrivacyNoticeResource::make($notice);
    }
}
