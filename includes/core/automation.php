<?php
namespace Portflow\Core;

if (!defined('APP_NAME')) {
    die('Access denied');
}

include_once __DIR__ . '/automation_store.php';

class Automation {
    private string $configPath;
    private array $config = [];

    public function __construct(?string $configPath = null) {
        $this->configPath = $configPath ?? __DIR__ . '/automation.json';
    }

    public function getConfig(): array {
        if (!empty($this->config)) {
            return $this->config;
        }

        if (!file_exists($this->configPath)) {
            return [];
        }

        $decoded = json_decode(file_get_contents($this->configPath), true);
        if (!is_array($decoded)) {
            return [];
        }

        $store = new AutomationStore();
        $overrideConfig = $store->getScriptOverrides();

        $this->config = $this->mergeConfig($decoded, $overrideConfig);
        return $this->config;
    }

    private function mergeConfig(array $baseConfig, array $overrideConfig): array {
        $merged = $baseConfig;

        if (!empty($overrideConfig['description_convention']) && is_array($overrideConfig['description_convention'])) {
            $merged['description_convention'] = array_replace_recursive(
                $baseConfig['description_convention'] ?? [],
                $overrideConfig['description_convention']
            );
        }

        foreach (['profiles', 'templates'] as $section) {
            if (empty($overrideConfig[$section]) || !is_array($overrideConfig[$section])) {
                continue;
            }

            if (!isset($merged[$section]) || !is_array($merged[$section])) {
                $merged[$section] = [];
            }

            foreach ($overrideConfig[$section] as $key => $overrideItem) {
                if (is_array($overrideItem) && isset($merged[$section][$key]) && is_array($merged[$section][$key])) {
                    $merged[$section][$key] = array_replace_recursive($merged[$section][$key], $overrideItem);
                } else {
                    $merged[$section][$key] = $overrideItem;
                }
            }
        }

        return $merged;
    }

    public function getProfiles(): array {
        $config = $this->getConfig();
        return $config['profiles'] ?? [];
    }

    public function getTemplates(): array {
        $config = $this->getConfig();
        return $config['templates'] ?? [];
    }

    public function getProfile(string $profileId): ?array {
        $profiles = $this->getProfiles();
        return $profiles[$profileId] ?? null;
    }

    public function getTemplate(string $templateId): ?array {
        $templates = $this->getTemplates();
        return $templates[$templateId] ?? null;
    }

    public function renderTemplate(string $templateId, string $profileId, array $variables = []): array {
        $template = $this->getTemplate($templateId);
        $profile = $this->getProfile($profileId);

        if (!$template || !$profile) {
            return [
                'profile' => $profile,
                'template' => $template,
                'commands' => [],
                'warnings' => ['Template oder Profil konnte nicht geladen werden.']
            ];
        }

        $warnings = [];
        $supportedProfiles = $template['supported_profiles'] ?? [];
        if (!empty($supportedProfiles) && !in_array($profileId, $supportedProfiles, true)) {
            $warnings[] = 'Dieses Template ist fuer das gewaehlte Profil nicht freigegeben.';
        }

        $resolvedVariables = $variables;
        if (!empty($template['uses_description_convention'])) {
            $descriptionValue = trim((string)($resolvedVariables['description'] ?? ''));
            if ($descriptionValue === '') {
                $config = $this->getConfig();
                $convention = $config['description_convention']['template'] ?? 'PF|{{portflow_id}}|{{connection_ref}}';
                $resolvedVariables['description'] = $this->renderString($convention, $resolvedVariables);
            }
        }

        $commands = [];
        $profilePreCommands = array_filter([
            $profile['enter_config'] ?? null,
        ]);

        foreach ($profilePreCommands as $command) {
            $commands[] = $this->renderString($command, $resolvedVariables);
        }

        foreach (($template['commands'] ?? []) as $command) {
            $rendered = trim($this->renderString($command, $resolvedVariables));
            if ($rendered !== '') {
                $commands[] = $rendered;
            }
        }

        if (!empty($profile['supports_commit']) && !empty($profile['commit'])) {
            $commands[] = $this->renderString($profile['commit'], $resolvedVariables);
        }

        if (!empty($profile['exit_config'])) {
            $commands[] = $this->renderString($profile['exit_config'], $resolvedVariables);
        }

        if (!empty($profile['save'])) {
            $commands[] = $this->renderString($profile['save'], $resolvedVariables);
        }

        if (!empty($profile['write_config'])) {
            $writeConfig = trim($this->renderString((string)$profile['write_config'], $resolvedVariables));
            if ($writeConfig !== '') {
                $normalizedWriteConfig = strtolower($writeConfig);
                if (in_array($normalizedWriteConfig, ['yes', 'true', '1'], true)) {
                    $writeConfig = 'Y';
                } elseif (in_array($normalizedWriteConfig, ['no', 'false', '0'], true)) {
                    $writeConfig = 'N';
                }
                $commands[] = $writeConfig;
            }
        }

        return [
            'profile' => $profile,
            'template' => $template,
            'variables' => $resolvedVariables,
            'commands' => $commands,
            'warnings' => $warnings
        ];
    }

    private function renderString(string $template, array $variables): string {
        return preg_replace_callback('/\{\{([a-zA-Z0-9_.-]+)\}\}/', function ($matches) use ($variables) {
            $key = $matches[1];
            $value = $variables[$key] ?? '';

            if (is_bool($value)) {
                return $value ? 'true' : 'false';
            }

            if (is_array($value)) {
                return implode(', ', $value);
            }

            return (string)$value;
        }, $template) ?? $template;
    }
}