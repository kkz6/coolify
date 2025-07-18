<?php

namespace App\Livewire\Project\Application;

use App\Actions\Docker\GetContainersStatus;
use App\Models\Application;
use App\Models\ApplicationPreview;
use Illuminate\Support\Collection;
use Livewire\Component;
use Visus\Cuid2\Cuid2;

class Previews extends Component
{
    public Application $application;

    public string $deployment_uuid;

    public array $parameters;

    public Collection $pull_requests;

    public int $rate_limit_remaining;

    protected $rules = [
        'application.previews.*.fqdn' => 'string|nullable',
    ];

    public function mount()
    {
        $this->pull_requests = collect();
        $this->parameters = get_route_parameters();
    }

    public function load_prs()
    {
        try {
            ['rate_limit_remaining' => $rate_limit_remaining, 'data' => $data] = githubApi(source: $this->application->source, endpoint: "/repos/{$this->application->git_repository}/pulls");
            $this->rate_limit_remaining = $rate_limit_remaining;
            $this->pull_requests = $data->sortBy('number')->values();
        } catch (\Throwable $e) {
            $this->rate_limit_remaining = 0;

            return handleError($e, $this);
        }
    }

    public function save_preview($preview_id)
    {
        try {
            $success = true;
            $preview = $this->application->previews->find($preview_id);
            if (data_get_str($preview, 'fqdn')->isNotEmpty()) {
                $preview->fqdn = str($preview->fqdn)->replaceEnd(',', '')->trim();
                $preview->fqdn = str($preview->fqdn)->replaceStart(',', '')->trim();
                $preview->fqdn = str($preview->fqdn)->trim()->lower();
                if (! validate_dns_entry($preview->fqdn, $this->application->destination->server)) {
                    $this->dispatch('error', 'Validating DNS failed.', "Make sure you have added the DNS records correctly.<br><br>$preview->fqdn->{$this->application->destination->server->ip}<br><br>Check this <a target='_blank' class='underline dark:text-white' href='https://coolify.io/docs/knowledge-base/dns-configuration'>documentation</a> for further help.");
                    $success = false;
                }
                check_domain_usage(resource: $this->application, domain: $preview->fqdn);
            }

            if (! $preview) {
                throw new \Exception('Preview not found');
            }
            $success && $preview->save();
            $success && $this->dispatch('success', 'Preview saved.<br><br>Do not forget to redeploy the preview to apply the changes.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function generate_preview($preview_id)
    {
        $preview = $this->application->previews->find($preview_id);
        if (! $preview) {
            $this->dispatch('error', 'Preview not found.');

            return;
        }
        if ($this->application->build_pack === 'dockercompose') {
            $preview->generate_preview_fqdn_compose();
            $this->application->refresh();
            $this->dispatch('success', 'Domain generated.');

            return;
        }

        $this->application->generate_preview_fqdn($preview->pull_request_id);
        $this->application->refresh();
        $this->dispatch('update_links');
        $this->dispatch('success', 'Domain generated.');
    }

    public function add(int $pull_request_id, ?string $pull_request_html_url = null)
    {
        try {
            if ($this->application->build_pack === 'dockercompose') {
                $this->setDeploymentUuid();
                $found = ApplicationPreview::where('application_id', $this->application->id)->where('pull_request_id', $pull_request_id)->first();
                if (! $found && ! is_null($pull_request_html_url)) {
                    $found = ApplicationPreview::create([
                        'application_id' => $this->application->id,
                        'pull_request_id' => $pull_request_id,
                        'pull_request_html_url' => $pull_request_html_url,
                        'docker_compose_domains' => $this->application->docker_compose_domains,
                    ]);
                }
                $found->generate_preview_fqdn_compose();
                $this->application->refresh();
            } else {
                $this->setDeploymentUuid();
                $found = ApplicationPreview::where('application_id', $this->application->id)->where('pull_request_id', $pull_request_id)->first();
                if (! $found && ! is_null($pull_request_html_url)) {
                    $found = ApplicationPreview::create([
                        'application_id' => $this->application->id,
                        'pull_request_id' => $pull_request_id,
                        'pull_request_html_url' => $pull_request_html_url,
                    ]);
                }
                $this->application->generate_preview_fqdn($pull_request_id);
                $this->application->refresh();
                $this->dispatch('update_links');
                $this->dispatch('success', 'Preview added.');
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function force_deploy_without_cache(int $pull_request_id, ?string $pull_request_html_url = null)
    {
        $this->deploy($pull_request_id, $pull_request_html_url, force_rebuild: true);
    }

    public function add_and_deploy(int $pull_request_id, ?string $pull_request_html_url = null)
    {
        $this->add($pull_request_id, $pull_request_html_url);
        $this->deploy($pull_request_id, $pull_request_html_url);
    }

    public function deploy(int $pull_request_id, ?string $pull_request_html_url = null, bool $force_rebuild = false)
    {
        try {
            $this->setDeploymentUuid();
            $found = ApplicationPreview::where('application_id', $this->application->id)->where('pull_request_id', $pull_request_id)->first();
            if (! $found && ! is_null($pull_request_html_url)) {
                ApplicationPreview::create([
                    'application_id' => $this->application->id,
                    'pull_request_id' => $pull_request_id,
                    'pull_request_html_url' => $pull_request_html_url,
                ]);
            }
            $result = queue_application_deployment(
                application: $this->application,
                deployment_uuid: $this->deployment_uuid,
                force_rebuild: $force_rebuild,
                pull_request_id: $pull_request_id,
                git_type: $found->git_type ?? null,
            );
            if ($result['status'] === 'skipped') {
                $this->dispatch('success', 'Deployment skipped', $result['message']);

                return;
            }

            return redirect()->route('project.application.deployment.show', [
                'project_uuid' => $this->parameters['project_uuid'],
                'application_uuid' => $this->parameters['application_uuid'],
                'deployment_uuid' => $this->deployment_uuid,
                'environment_uuid' => $this->parameters['environment_uuid'],
            ]);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    protected function setDeploymentUuid()
    {
        $this->deployment_uuid = new Cuid2;
        $this->parameters['deployment_uuid'] = $this->deployment_uuid;
    }

    public function stop(int $pull_request_id)
    {
        try {
            $server = $this->application->destination->server;

            if ($this->application->destination->server->isSwarm()) {
                instant_remote_process(["docker stack rm {$this->application->uuid}-{$pull_request_id}"], $server);
            } else {
                $containers = getCurrentApplicationContainerStatus($server, $this->application->id, $pull_request_id)->toArray();
                $this->stopContainers($containers, $server);
            }

            GetContainersStatus::run($server);
            $this->application->refresh();
            $this->dispatch('containerStatusUpdated');
            $this->dispatch('success', 'Preview Deployment stopped.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function delete(int $pull_request_id)
    {
        try {
            $server = $this->application->destination->server;

            if ($this->application->destination->server->isSwarm()) {
                instant_remote_process(["docker stack rm {$this->application->uuid}-{$pull_request_id}"], $server);
            } else {
                $containers = getCurrentApplicationContainerStatus($server, $this->application->id, $pull_request_id)->toArray();
                $this->stopContainers($containers, $server);
            }

            ApplicationPreview::where('application_id', $this->application->id)
                ->where('pull_request_id', $pull_request_id)
                ->first()
                ->delete();

            $this->application->refresh();
            $this->dispatch('update_links');
            $this->dispatch('success', 'Preview deleted.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    private function stopContainers(array $containers, $server, int $timeout = 30)
    {
        if (empty($containers)) {
            return;
        }
        $containerNames = [];
        foreach ($containers as $container) {
            $containerNames[] = str_replace('/', '', $container['Names']);
        }

        $containerList = implode(' ', array_map('escapeshellarg', $containerNames));
        $commands = [
            "docker stop --time=$timeout $containerList",
            "docker rm -f $containerList",
        ];

        instant_remote_process(
            command: $commands,
            server: $server,
            throwError: false
        );
    }
}
