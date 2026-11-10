<?php

declare(strict_types=1);

return [
    'scheduler_max_age_seconds' => (int) env('SCHEDULER_HEARTBEAT_MAX_AGE', 180),
    'pipeline_max_age_seconds' => (int) env('PIPELINE_HEARTBEAT_MAX_AGE', 4500),
    'ai_max_age_seconds' => (int) env('AI_HEARTBEAT_MAX_AGE', 600),
];
