<?php

// Zum Testen $dbg  de/aktivieren und 
// Aufruf: http://localhost/wrk/aimail/api/aicall.php?dbg=2
declare(strict_types=1);
$dbg = $_GET['dbg'] ?? 0; // Bitweise 0: Aus 1:Input 2:Output=Input 4:Output aus File od. manuell
session_start();

header('Content-Type: application/json; charset=utf-8');

include_once __DIR__ . '/../secret/keys.inc.php';
$apiKey = OPENAI_API_KEY;

if (!$apiKey) {
  http_response_code(500);
  echo json_encode(["error" => "OPENAI_API_KEY ist nicht gesetzt."]);
  exit;
}

// Request-Body lesen (JSON)
$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
$userText = trim($payload['text'] ?? '');

// Testweise Input simulieren $dbg & 1
if($dbg & 1) $userText = "Sehr Damen u Herren, wie geplant, nix passiert. Uffbasse! Jo";

if ($userText === '') {
  http_response_code(400);
  echo json_encode(["error" => "Kein Benutzertext übergeben."]);
  exit;
}

// Testweise direkte Ausgabe des Inputs $dbg & 2
if($dbg & 2) {
  echo json_encode(["answer" => "Input war '$userText'"], JSON_UNESCAPED_UNICODE); 
  exit;
}

// OpenAI Request
$data = [
  "model" => "gpt-4.1-mini",
  "temperature" => 0.2,
  "max_output_tokens" => 700,
  "input" => [
    [
      "role" => "system",
      "content" =>
      "Du bist Lektor für geschäftliche E-Mails auf Deutsch.
Regeln:
- Korrigiere Rechtschreibung, Grammatik, Zeichensetzung und Formatierung.
- Bewahre Bedeutung, Fakten, Namen, Zahlen und Absicht. Nichts erfinden.
- Ton: professionell, freundlich. Keine Floskeln hinzufügen, wenn nicht nötig.
- Gib die korrigierte E-Mail im Feld output_text zurück. Falls keine Änderungen nötig sind, gib den Originaltext unverändert zurück.
- Liste relevante Änderungen kurz in changes."
    ],
    ["role" => "user", "content" => "E-MAIL:\n" . $userText]
  ],
  "text" => [
    "format" => [
      "type" => "json_schema",
      "name" => "email_review",
      "schema" => [
        "type" => "object",
        "additionalProperties" => false,
        "properties" => [
          "corrected_email" => ["type" => "string"],
          "changes" => [
            "type" => "array",
            "items" => ["type" => "string"]
          ],
          "issues" => [
            "type" => "array",
            "items" => ["type" => "string"]
          ]
        ],
        "required" => ["corrected_email", "changes", "issues"]
      ]
    ]
  ]
];

// Testweise Antwort 
if($dbg & 4 ){
  $response = file_get_contents(__DIR__ . '/../docus/testresponse.json');
  $httpCode = 200;
  $curlErr = '';
}else{

$ch = curl_init("https://api.openai.com/v1/responses");
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_POST => true,
  CURLOPT_HTTPHEADER => [
    "Content-Type: application/json",
    "Authorization: Bearer " . $apiKey,
  ],
  CURLOPT_POSTFIELDS => json_encode($data, JSON_UNESCAPED_UNICODE),
  CURLOPT_TIMEOUT => 30,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);
// curl_close($ch);
} // Ende von $dbg & 4

if ($response === false) {
  http_response_code(502);
  echo json_encode(["error" => "cURL Fehler: " . $curlErr]);
  exit;
}

$result = json_decode($response, true);

// Fehler von OpenAI sauber weitergeben
if ($httpCode < 200 || $httpCode >= 300) {
  http_response_code(502);
  $msg = $result["error"]["message"] ?? ("OpenAI Fehler (HTTP $httpCode).");
  echo json_encode(["error" => $msg, "details" => $result], JSON_UNESCAPED_UNICODE);
  exit;
}

// $comp_text ist JSON-IN-JSON. Lt. API korrekt, trotz schema aber gruselig. $answer ist also Objekt
$answer = $result['output'][0]['content'][0]['text'];
if ($answer === null)  $answer = "(ERROR: Keine verwertbare Textausgabe)";

$reply =  ["answer" => $answer]; // answer JSON-IN-JSON
echo json_encode($reply, JSON_UNESCAPED_UNICODE);
