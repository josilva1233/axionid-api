<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;
use OpenApi\Attributes as OA;

#[OA\Info(title: "AxionID API", version: "1.0.0", description: "Documentação oficial do sistema de identidade AxionID")]
#[OA\Server(url: "http://127.0.0.1:8000", description: "Servidor Local")]
#[OA\SecurityScheme(
    securityScheme: 'sanctum',
    type: 'http',
    scheme: 'bearer',
    bearerFormat: 'JWT',
    description: "Insira o token gerado no login (ex: 1|abcde...) para autorizar as chamadas."
)]
class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;

    #[OA\Get(
        path: "/",
        summary: "Status da API",
        tags: ["Sistema"],
        responses: [
            new OA\Response(response: "200", description: "API Online")
        ]
    )]
    public function healthCheck() {
        return response()->json(['status' => 'online']);
    }
}