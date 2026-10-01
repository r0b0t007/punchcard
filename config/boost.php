<?php

declare(strict_types=1);

// Only overrides live here; Boost merges the rest from its package defaults.
return [

    'guidelines' => [
        // Deploys go through Ploi deploy webhooks, not Laravel Cloud.
        'exclude' => ['deployments'],
    ],

    'agents' => [
        'claude_code' => [
            // Claude Code reads CLAUDE.md; Boost's default AGENTS.md is gitignored here.
            'guidelines_path' => 'CLAUDE.md',
        ],
    ],

];
