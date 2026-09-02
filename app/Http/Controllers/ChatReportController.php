<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ChatReportController extends Controller
{
    public function chat(Request $request)
    {
        return response()->json(['message' => 'Chat funcionando! Mensagem: ' . $request->input('message')]);
    }

    public function status()
    {
        return response()->json(['status' => 'online']);
    }

    public function clearHistory()
    {
        return response()->json(['message' => 'Histórico limpo']);
    }
}