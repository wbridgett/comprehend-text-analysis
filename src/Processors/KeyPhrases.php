<?php

namespace wbridgett\TextAnalysis\Processors;

use Aws\Comprehend\ComprehendClient;

class KeyPhrases {

    private $client;

    private $data = [];

    private $map = [];

    private $rawResult = [];

    public function __construct(ComprehendClient $client) {
        $this->client = $client;
    }

    public function addData($index, $column, $value)
    {
        //skip empty values
        if(! empty($value)) {
            $this->data[] = $value;
        }
        $this->map[] = ['index' => $index, 'column' => $column, 'value' => $value, 'key' => empty($value) ? -1 : count($this->data) - 1];
    }

    public function run()
    {

        $this->rawResult = $this->client->batchDetectKeyPhrases([
            'LanguageCode' => 'en',
            'TextList' => $this->data
        ]);

        return ['result' => $this->rawResult, 'map' => $this->map];
    }

}