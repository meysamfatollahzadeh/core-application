<?php

require '/app/vendor/autoload.php';

use GuzzleHttp\Client;

$http = new Client(['timeout' => 40, 'http_errors' => false]);
$base = getenv('CAMUNDA_URL');
$unauthorized = $http->post($base.'/v2/process-definitions/search', ['json' => new stdClass]);
if ($unauthorized->getStatusCode() !== 401) {
    throw new RuntimeException('Camunda must reject unauthenticated requests.');
}
$tokenResponse = $http->post(getenv('OIDC_INTERNAL_ISSUER').'/protocol/openid-connect/token', ['form_params' => [
    'grant_type' => 'client_credentials',
    'client_id' => getenv('CAMUNDA_CLIENT_ID'),
    'client_secret' => getenv('CAMUNDA_CLIENT_SECRET'),
]]);
$tokens = json_decode((string) $tokenResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (! isset($tokens['access_token'])) {
    throw new RuntimeException('Camunda service account token request failed.');
}
$headers = ['Authorization' => 'Bearer '.$tokens['access_token']];
$id = 'InfrastructureSmoke_'.bin2hex(random_bytes(5));
$bpmn = '<?xml version="1.0" encoding="UTF-8"?><definitions xmlns="http://www.omg.org/spec/BPMN/20100524/MODEL" targetNamespace="http://organization.local/infrastructure"><process id="'.$id.'" isExecutable="true"><startEvent id="start"><outgoing>flow</outgoing></startEvent><sequenceFlow id="flow" sourceRef="start" targetRef="end"/><endEvent id="end"><incoming>flow</incoming></endEvent></process></definitions>';
$deploymentResponse = $http->post($base.'/v2/deployments', ['headers' => $headers, 'multipart' => [
    ['name' => 'resources', 'contents' => $bpmn, 'filename' => 'infrastructure-smoke.bpmn', 'headers' => ['Content-Type' => 'application/xml']],
]]);
$deployment = json_decode((string) $deploymentResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
if ($deploymentResponse->getStatusCode() >= 300) {
    throw new RuntimeException('Deployment failed: '.json_encode($deployment));
}
$resourceKey = $deployment['deployments'][0]['processDefinition']['processDefinitionKey'] ?? null;
try {
    $response = $http->post($base.'/v2/process-instances', ['headers' => $headers, 'json' => [
        'processDefinitionId' => $id, 'awaitCompletion' => true, 'requestTimeout' => 30000,
    ]]);
    $instance = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    if ($response->getStatusCode() >= 300 || ! isset($instance['processInstanceKey'])) {
        throw new RuntimeException('Process execution failed: '.json_encode($instance));
    }
    echo "PASS: authenticated internal deployment and process completion\n";
} finally {
    if ($resourceKey) {
        $deleted = $http->post($base.'/v2/resources/'.$resourceKey.'/deletion', ['headers' => $headers]);
        if ($deleted->getStatusCode() >= 300) {
            throw new RuntimeException('Disposable process definition cleanup failed.');
        }
        echo "PASS: disposable process definition removed\n";
    }
}
