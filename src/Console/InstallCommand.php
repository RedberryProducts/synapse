<?php

namespace Redberry\Synapse\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;
use PhpToken;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'synapse:install')]
class InstallCommand extends Command
{
    protected $signature = 'synapse:install {--no-migrate : Skip running migrations}';

    protected $description = 'Install all of the Synapse resources';

    public function handle(): int
    {
        $this->components->info('Publishing Synapse resources...');

        $this->callSilent('vendor:publish', ['--tag' => 'synapse-config']);
        $this->callSilent('vendor:publish', ['--tag' => 'synapse-migrations']);
        $this->callSilent('vendor:publish', ['--tag' => 'synapse-provider']);

        $this->registerSynapseServiceProvider();

        if (! $this->option('no-migrate')) {
            $this->components->info('Running migrations...');
            $this->call('migrate');
        }

        $this->components->info('Synapse installed successfully.');

        return self::SUCCESS;
    }

    protected function registerSynapseServiceProvider(): void
    {
        $path = app_path('Providers/AppServiceProvider.php');

        if (! File::exists($path)) {
            throw new RuntimeException(
                'Unable to register Synapse: app/Providers/AppServiceProvider.php was not found. Recreate it or register SynapseServiceProvider manually.'
            );
        }

        $contents = File::get($path);
        $eol = str_contains($contents, "\r\n") ? "\r\n" : "\n";
        $registration = implode($eol, [
            "        if (\$this->app->environment('local') &&",
            '            class_exists(\\Redberry\\Synapse\\SynapseApplicationServiceProvider::class)) {',
            '            $this->app->register(SynapseServiceProvider::class);',
            '        }',
        ]);
        $tokens = $this->codeTokens($contents);
        $bodyIndex = $this->registerBodyIndex($tokens);
        $blockTokens = $this->codeTokens('<?php '.$registration);
        $candidate = $bodyIndex === null ? [] : $this->findRegistration($tokens, $bodyIndex, $blockTokens);
        $hasRegistration = $candidate !== [];
        $remainingCode = $contents;

        if ($hasRegistration) {
            $first = $candidate[0];
            $last = $candidate[count($candidate) - 1];
            $remainingCode = substr_replace($contents, '', $first->pos, $last->pos + strlen($last->text) - $first->pos);
        }

        // Imports must also be rejected: an alias can hide an unguarded registration.
        if (preg_match('/\bSynapseServiceProvider\b/i', $remainingCode) === 1) {
            throw new RuntimeException(
                'Unable to verify the existing Synapse registration. Remove the existing Synapse registration from AppServiceProvider::register() and any SynapseServiceProvider references outside the generated guarded block (including imports and commented copies), then rerun synapse:install to add the local and class_exists guards.'
            );
        }

        if (! $hasRegistration) {
            if ($bodyIndex === null) {
                throw new RuntimeException(
                    'Unable to register Synapse: App\\Providers\\AppServiceProvider::register() was not found.'
                );
            }

            $updatedContents = substr_replace($contents, $eol.$registration.$eol, $tokens[$bodyIndex]->pos + 1, 0);

            File::put($path, $updatedContents);
        }

        $bootstrap = $this->laravel->getBootstrapProvidersPath();

        if (File::exists($bootstrap) && in_array('App\\Providers\\SynapseServiceProvider', require $bootstrap, true)) {
            ServiceProvider::removeProviderFromBootstrapFile(
                'App\\Providers\\SynapseServiceProvider',
                strict: true,
            );
        }
    }

    /**
     * @return list<PhpToken>
     */
    private function codeTokens(string $contents): array
    {
        return array_values(array_filter(
            PhpToken::tokenize($contents),
            fn (PhpToken $token): bool => ! $token->is([T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]),
        ));
    }

    /**
     * @param  list<PhpToken>  $tokens
     */
    private function registerBodyIndex(array $tokens): ?int
    {
        $texts = array_column($tokens, 'text');

        foreach ($tokens as $index => $token) {
            if ($token->id !== T_PUBLIC) {
                continue;
            }

            foreach ([['public', 'function', 'register', '(', ')', '{'], ['public', 'function', 'register', '(', ')', ':', 'void', '{']] as $signature) {
                if (array_slice($texts, $index, count($signature)) === $signature) {
                    return $index + count($signature) - 1;
                }
            }
        }

        return null;
    }

    /**
     * @param  list<PhpToken>  $tokens
     * @param  list<PhpToken>  $blockTokens
     * @return list<PhpToken>
     */
    private function findRegistration(array $tokens, int $bodyIndex, array $blockTokens): array
    {
        $depth = 1;
        $blockTexts = array_column($blockTokens, 'text');

        for ($index = $bodyIndex + 1; $index < count($tokens) && $depth > 0; $index++) {
            $token = $tokens[$index];

            if ($depth === 1 && $token->id === T_IF) {
                $candidate = array_slice($tokens, $index, count($blockTokens));

                if (array_column($candidate, 'text') === $blockTexts) {
                    return $candidate;
                }
            }

            if (in_array($token->id, [ord('{'), T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                $depth++;
            } elseif ($token->id === ord('}')) {
                $depth--;
            }
        }

        return [];
    }
}
