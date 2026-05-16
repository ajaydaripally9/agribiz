<?php
header('Content-Type: application/json');

// In a real app, you would use an API like OpenWeatherMap
// For this Enterprise-Grade demo, we simulate a sophisticated weather engine
$weather_data = [
    'temp' => 32,
    'humidity' => 45,
    'wind_speed' => 12,
    'condition' => 'Clear',
    'rain_probability' => 5,
    'location' => 'Siddipet, Telangana'
];

$advisory = "Perfect time to spray. No rain expected for the next 48 hours.";
$status = "ideal"; // ideal, caution, danger
$color = "#22c55e";

if ($weather_data['rain_probability'] > 50) {
    $advisory = "⚠️ High probability of rain today. Postpone spraying to avoid chemical runoff.";
    $status = "danger";
    $color = "#ef4444";
} elseif ($weather_data['wind_speed'] > 18) {
    $advisory = "🌬️ High wind speeds detected. Spraying might drift to other fields. Exercise caution.";
    $status = "caution";
    $color = "#f59e0b";
} elseif ($weather_data['temp'] > 38) {
    $advisory = "🌡️ Extreme heat alert. Apply fertilizers in early morning or late evening only.";
    $status = "caution";
    $color = "#f59e0b";
}

echo json_encode([
    'status' => 'success',
    'weather' => $weather_data,
    'advisory' => [
        'text' => $advisory,
        'status' => $status,
        'color' => $color,
        'title' => 'SPRAYING ADVISORY'
    ],
    'telugu' => [
        'title' => 'స్ప్రేయింగ్ సలహా',
        'text' => ($status === 'ideal') ? "ఎరువులు వేయడానికి ఇది సరైన సమయం. వచ్చే 48 గంటల్లో వర్షం సూచన లేదు." : "వాతావరణం అనుకూలంగా లేదు. దయచేసి జాగ్రత్తగా ఉండండి."
    ]
]);
