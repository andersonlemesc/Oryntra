<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Api\RequestPresignedUpload;
use App\Http\Requests\Api\V1\RequestUploadRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class UploadController extends ApiController
{
    public function store(RequestUploadRequest $request, RequestPresignedUpload $action): JsonResponse
    {
        $purpose = $request->purpose();

        $token = $request->user()?->currentAccessToken();
        if ($token === null || ! $token->can($purpose->ability())) {
            abort(Response::HTTP_FORBIDDEN, 'Token sem permissão para este tipo de upload (' . $purpose->ability() . ').');
        }

        $mime = $request->string('mime')->value();
        if (! in_array($mime, $purpose->allowedMimes(), true)) {
            throw ValidationException::withMessages([
                'mime' => 'Tipo de arquivo não permitido para ' . $purpose->value . '. Permitidos: ' . implode(', ', $purpose->allowedMimes()),
            ]);
        }

        $size = $request->integer('size');
        if ($size > $purpose->maxBytes()) {
            throw ValidationException::withMessages([
                'size' => 'Arquivo excede o limite de ' . ($purpose->maxBytes() / 1024 / 1024) . ' MB.',
            ]);
        }

        $result = $action->execute(
            workspaceId: $this->workspaceId(),
            purpose: $purpose,
            filename: $request->string('filename')->value(),
            mime: $mime,
            size: $size,
        );

        return response()->json(['data' => $result], Response::HTTP_CREATED);
    }
}
