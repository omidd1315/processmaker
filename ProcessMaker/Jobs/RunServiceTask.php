<?php

namespace ProcessMaker\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use ProcessMaker\Exception\ScriptException;
use ProcessMaker\Facades\WorkflowManager;
use ProcessMaker\Managers\DataManager;
use ProcessMaker\Models\Process as Definitions;
use ProcessMaker\Models\ProcessRequest;
use ProcessMaker\Models\ProcessRequestToken;
use ProcessMaker\Models\Script;
use ProcessMaker\Nayra\Contracts\Bpmn\ServiceTaskInterface;
use ProcessMaker\Repositories\DefinitionsRepository;
use Throwable;

class RunServiceTask extends BpmnAction implements ShouldQueue
{
    public $definitionsId;
    public $instanceId;
    public $tokenId;
    public $data;

    /**
     * Create a new job instance.
     *
     * @param \ProcessMaker\Models\Process $definitions
     * @param \ProcessMaker\Models\ProcessRequest $instance
     * @param \ProcessMaker\Models\ProcessRequestToken $token
     * @param array $data
     */
    public function __construct(Definitions $definitions, ProcessRequest $instance, ProcessRequestToken $token, array $data)
    {
        $this->onQueue('bpmn');
        $this->definitionsId = $definitions->getKey();
        $this->instanceId = $instance->getKey();
        $this->tokenId = $token->getKey();
        $this->elementId = $token->getProperty('element_ref');
        $this->data = $data;
    }

    /**
     * Execute the script task.
     *
     * @return void
     */
    public function action(ProcessRequestToken $token = null, ServiceTaskInterface $element = null, Definitions $processModel, ProcessRequest $instance)
    {
        $this->perfStart();
        // Exit if the task was completed or closed
        if (!$token || !$element) {
            return;
        }
        $implementation = $element->getImplementation();
        $configuration = json_decode($element->getProperty('config'), true);
        $this->perfLog('LoadConfig');
        // Check to see if we've failed parsing.  If so, let's convert to empty array.
        if ($configuration === null) {
            $configuration = [];
        }
        try {
            if (empty($implementation)) {
                throw new ScriptException('Service task implementation not defined');
            } else {
                $script = Script::where('key', $implementation)->first();
            }
            if (empty($script)) {
                throw new ScriptException('Service task not implemented: ' . $implementation);
            }
            $this->perfLog('LoadScript');

            $this->unlock();
            $this->perfLog('Unlock');
            $dataManager = new DataManager();
            $data = $dataManager->getData($token);
            $this->perfLog('LoadData');
            $response = $script->runScript($data, $configuration, $token->getId());
            $this->perfLog('RunScript');
            $this->withUpdatedContext(function ($engine, $instance, $element, $processModel, $token) use ($response) {
                // Exit if the task was completed or closed
                if (!$token || !$element) {
                    return;
                }
                $this->perfLog('UpdateContext');
                // Update data
                if (is_array($response['output'])) {
                    // Validate data
                    WorkflowManager::validateData($response['output'], $processModel, $element);
                    $dataManager = new DataManager();
                    $dataManager->updateData($token, $response['output']);
                    $engine->runToNextState();
                }
                $element->complete($token);
                $this->engine = $engine;
                $this->instance = $instance;
            });
            $this->perfLog('UpdateData');
        } catch (Throwable $exception) {
            // Change to error status
            $token->setStatus(ServiceTaskInterface::TOKEN_STATE_FAILING);
            $error = $element->getRepository()->createError();
            $error->setName($exception->getMessage());
            $token->setProperty('error', $error);
            Log::info('Service task failed: ' . $implementation . ' - ' . $exception->getMessage());
            Log::error($exception->getTraceAsString());
        }
    }

    /**
     * When Job fails
     */
    public function failed(Throwable $exception)
    {
        if (!$this->tokenId) {
            Log::error('Script failed: ' . $exception->getMessage());
            return;
        }
        Log::error('Script (#' . $this->tokenId . ') failed: ' . $exception->getMessage());
        Log::error($exception->getTraceAsString());
        $token = ProcessRequestToken::find($this->tokenId);
        if ($token) {
            $token->setStatus(ServiceTaskInterface::TOKEN_STATE_FAILING);
            $repository = new DefinitionsRepository();
            $error = $repository->createError();
            $error->setName($exception->getMessage());
            $token->setProperty('error', $error);
            $token->save();
        }
    }
}
