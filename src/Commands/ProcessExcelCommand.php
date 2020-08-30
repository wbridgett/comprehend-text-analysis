<?php

namespace wbridgett\TextAnalysis\Commands;

use Aws\Comprehend\ComprehendClient;
use Aws\Credentials\Credentials;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ProcessExcelCommand extends Command
{
    protected static $defaultName = 'text:excel';

    protected function configure()
    {
        $this
        ->setDescription('Proccess Excel File')
        ->setHelp('Allows you to pass an excel document and process text columns')
        ->addArgument('file', InputArgument::REQUIRED, 'Excel File')
        ->addArgument('startReading', InputArgument::REQUIRED, 'Row to start reading at')
        ->addArgument('columns', InputArgument::IS_ARRAY, 'Text Columns');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln('Loading file ' . pathinfo($input->getArgument('file'), PATHINFO_BASENAME));

        //Open Excel file and starting reading
        $reader = IOFactory::createReader("Xlsx");
        $inputSpreadsheet = $reader->load($input->getArgument('file'));
        $inputData = $inputSpreadsheet->getActiveSheet()->toArray(null, true, true, true);

        //Create Client
        $credentials = new Credentials(getenv('AWS_KEY'), getenv('AWS_SECRET'));
        $client = new ComprehendClient([
            'version'     => 'latest',
            'region'      => getenv('AWS_REGION'),
            'credentials' => $credentials
        ]);

        $ouputData = [];
        $sentimentHeaders = [];
        $keyPhrasesByColumn = [];
        foreach ($input->getArgument('columns') as $column) {
            $keyPhrasesByColumn[$inputSpreadsheet->getActiveSheet()->getCell("{$column}1")->getFormattedValue()] = [];
            $sentimentHeaders[] = $inputSpreadsheet->getActiveSheet()->getCell("{$column}1")->getFormattedValue();
            $sentimentHeaders[] = "Sentiment";
            $sentimentHeaders[] = "Positive";
            $sentimentHeaders[] = "Negative";
            $sentimentHeaders[] = "Neutral";
            $sentimentHeaders[] = "Mixed";
        }
        $ouputData[] = $sentimentHeaders;
        
        foreach ($inputData as $key => $row) {
            //skip rows, most likely headers
            if($key >= (int) $input->getArgument('startReading')) {
                $rowOutput = [];
                foreach ( $input->getArgument('columns') as $column) {
                    $value = $row[strtoupper($column)];
                    if(! empty($value)) {
                        $sentiment = $client->detectSentiment([
                            'LanguageCode' => 'en',
                            'Text' => $value
                        ]);
                        $result = $client->detectKeyPhrases([
                            'LanguageCode' => 'en',
                            'Text' => $value,
                        ]);
                        $keyPhrasesByColumn[$inputSpreadsheet->getActiveSheet()->getCell("{$column}1")->getFormattedValue()] = array_merge($keyPhrasesByColumn[$inputSpreadsheet->getActiveSheet()->getCell("{$column}1")->getFormattedValue()], $result['KeyPhrases']);
                        $rowOutput[] = $value;
                        $rowOutput[] = $sentiment['Sentiment'];
                        $rowOutput[] = $sentiment['SentimentScore']['Positive'];
                        $rowOutput[] = $sentiment['SentimentScore']['Negative'];
                        $rowOutput[] = $sentiment['SentimentScore']['Neutral'];
                        $rowOutput[] = $sentiment['SentimentScore']['Mixed'];
                    } else {
                        $rowOutput[] = '';
                        $rowOutput[] = '';
                        $rowOutput[] = '';
                        $rowOutput[] = '';
                        $rowOutput[] = '';
                        $rowOutput[] = '';
                    }
                }
                $ouputData[] = $rowOutput;
            }
        }

        $output = new Spreadsheet();

        $output->getActiveSheet()->fromArray($ouputData);

        foreach ($keyPhrasesByColumn as $key => $column) {
            $phrasesSheet = new Worksheet($output, $key);
            $phrasesData = [
                ['raw', 'lowered', 'upper']
            ];
            foreach ($column as $word) {
                $phrasesData[] = [$word['Text'], strtolower($word['Text']), strtoupper($word['Text'])];
                
            }
            $output->addSheet($phrasesSheet);
            $output->getSheetByName($key)->fromArray($phrasesData);
        }

        $writer = new Xlsx($output);
        $writer->save("results.xlsx");

        return 0;
    }
}