<?php

namespace FinancialValidador;

use DateTime;
use Exception;
use League\Csv\Reader;
use League\Csv\Writer;
use MapasCulturais\App;
use League\Csv\Statement;
use RegistrationPayments\Payment;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Registration;
use MapasCulturais\Entities\RegistrationEvaluation;

/**
 * Registration Controller
 *
 * By default this controller is registered with the id 'registration'.
 *
 *  @property-read \MapasCulturais\Entities\Registration $requestedEntity The Requested Entity
 */
// class AldirBlanc extends \MapasCulturais\Controllers\EntityController {
class Controller extends \MapasCulturais\Controllers\Registration
{
    protected $config = [];

    protected $instanceConfig = [];

    protected $columns = [
        'NUMERO',
        'NOME_COMPLETO',
        'CPF',
        'VALIDACAO',
        'OBSERVACOES',
        'STATUS',
        'DATA 1',
        'VALOR 1',
        'DATA 2',
        'VALOR 2',
        'DATA 3',
        'VALOR 3',
        'DATA 4',
        'VALOR 4',
        'DATA 5',
        'VALOR 5',
        'DATA 6',
        'VALOR 6',
        'DATA 7',
        'VALOR 7',
        'DATA 8',
        'VALOR 8',
        'DATA 9',
        'VALOR 9',
        'DATA 10',
        'VALOR 10',
        'DATA 11',
        'VALOR 11',
        'DATA 12',
        'VALOR 12'
    ];

    /**
     * Retorna uma instância do controller
     * @param string $controller_id 
     * @return GenericValidator 
     */
    static public function i(string $controller_id): \MapasCulturais\Controller {
        $instance = parent::i($controller_id);
        $instance->init($controller_id);

        return $instance;
    }

    protected function init($controller_id) {
        if (!$this->_initiated) {
            $this->plugin = Plugin::getInstanceBySlug($controller_id);
            $this->config = $this->plugin->config;

            $this->_initiated = true;
        }
    }

    
    protected function exportInit(Opportunity $opportunity) {
        $this->requireAuthentication();

        if (!$opportunity->canUser('@control')) {
            echo "Não autorizado";
            die();
        }

        $this->registerRegistrationMetadata($opportunity);

        //Seta o timeout
        ini_set('max_execution_time', 0);
        ini_set('memory_limit', '768M');

    }

    /**
     * Retorna as inscrições
     * @param Opportunity $opportunity 
     * @return Registration[]
     */
    protected function getRegistrations(Opportunity $opportunity){
        
        $app = App::i();

        $dql_params = [ 'opportunity_Id' => $opportunity->id ];

        $from = $this->data['from'] ?? '';
        $to = $this->data['to'] ?? '';
        $only_unprocessed = $this->data['only_unprocessed'] ?? false;

        if ($from && !DateTime::createFromFormat('Y-m-d', $from)) {
            throw new \Exception("O formato do parâmetro `from` é inválido.");
        }

        if ($to && !DateTime::createFromFormat('Y-m-d', $to)) {
            throw new \Exception("O formato do parâmetro `to` é inválido.");
        }

        $dql_from = '';
        if ($from) {
            //Data ínicial
            $dql_params['from'] (new DateTime($from))->format('Y-m-d 00:00');
            $dql_from = "e.sentTimestamp >= :from AND";
        }

        $dql_to = '';
        if ($to) {
            //Data Final
            $dql_params['to'] (new DateTime($to))->format('Y-m-d 00:00');
            $dql_to = "e.sentTimestamp >= :to AND";
        }

        $dql = "
            SELECT
                e
            FROM
                MapasCulturais\Entities\Registration e
            WHERE
                $dql_to
                $dql_from
                e.status IN (1,10) AND
                e.opportunity = :opportunity_Id";

        $query = $app->em->createQuery($dql);

        $query->setParameters($dql_params);

        $result = $query->getResult();

        /**
         * remove da lista as inscrições não homologadas, as já validadas por
         * este validador e as inscrições que não tenham sido validadas pelos 
         * validadores requeridos.
         */ 
        $registrations = [];

        $repo = $app->repo('RegistrationEvaluation');
        $validator_user = $this->plugin->getUser();

        foreach ($result as $registration) {
            $evaluations = $repo->findBy(['registration' => $registration, 'status' => 1]);
            
            $eligible = true;

            if ($only_unprocessed) {
                // verifica se este validador já validou esta inscrição
                foreach ($evaluations as $evaluation) {
                    if($validator_user->equals($evaluation->user)) {
                        $eligible = false;
                    }
                }
            }
            
            /**  
             * se configurado, verifica se a inscrição está homologada
             * @todo: implementar para outros métodos de avaliação 
             */
            if ($this->config['exportador_requer_homologacao']) {    
                $homologado = false;

                // tem que ter uma avaliação com status `selecionado` (10)
                foreach ($evaluations as $evaluation) {
                    if ((!$evaluation->user->validator_for) && $evaluation->result == '10') {
                        $homologado = true;
                    }
                }

                // mas não pode ter uma avaliação com status diferente de `selecionado` (2, 3)
                foreach ($evaluations as $evaluation) {
                    if ((!$evaluation->user->validator_for) && $evaluation->result != '10') {
                        $homologado = false;
                    }
                }

                if(!$homologado) {
                    $eligible = false;
                }
            }

            /**  
             * se configurado, verifica se a inscrição está validada pelos validadores
             * @todo: implementar para outros métodos de avaliação 
             */
            foreach ($this->config['exportador_requer_validacao'] as $validador_slug) {
                if(!$eligible) {
                    continue;
                }
                $validated = false;
                foreach ($evaluations as $evaluation) {
                    if ($evaluation->user->validator_for == $validador_slug && $evaluation->result == '10') {
                        $validated = true;
                    }
                }
                if (!$validated) {
                    $eligible = false;
                }
            }

            if($eligible) {
                $registrations[] = $registration;
            }
        }


        $app->applyHookBoundTo($this, 'validator(' . $this->plugin->getSlug() . ').registrations', [&$registrations, $opportunity]);

        return $registrations;        
    }

    protected function generateCSV(array $registrations):string {
        /**
         * Array com header do documento CSV
         * @var array $headers
         */
        $headers = $this->columns;

        $csv_data = [];


        $tratament = $this->config['fields_tratament']; 

        foreach ($registrations as $i => $registration) {  
           
            $csv_data[$i] = [
                'NUMERO' => $registration->number,
                'NOME_COMPLETO' => $tratament($registration, 'NOME_COMPLETO'),
                'CPF' => $tratament($registration, 'CPF'),
                'VALIDACAO' => null,
                'OBSERVACOES' => null,
                'DATA 1' => null,
                'VALOR 1' => null,
                'DATA 2' => null,
                'VALOR 2' => null,
                'DATA 3' => null,
                'VALOR 3' => null,
                'DATA 4' => null,
                'VALOR 4' => null,
                'DATA 5' => null,
                'VALOR 5' => null,
                'DATA 6' => null,
                'VALOR 6' => null,
                'DATA 7' => null,
                'VALOR 7' => null,
                'DATA 8' => null,
                'VALOR 8' => null,
                'DATA 9' => null,
                'VALOR 9' => null,
                'DATA 10' => null,
                'VALOR 10' => null,
                'DATA 11' => null,
                'VALOR 11' => null,
                'DATA 12' => null,
                'VALOR 12' => null                
            ];            
        }        
        
        $validador = $this->plugin->getSlug();
        $hash = md5(json_encode($csv_data));

        $dir = PRIVATE_FILES_PATH . "financeiro/";

        $filename =  $dir . "{$validador}-{$hash}.csv";

        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        $stream = fopen($filename, 'w');

        $csv = Writer::createFromStream($stream);
        $csv->setDelimiter(";");

        $csv->insertOne($headers);

        foreach ($csv_data as $csv_line) {
            $csv->insertOne($csv_line);
        }

        return $filename;
    }

    /**
     * Exportador 
     *
     * Implementa o sistema de exportação para a lei AldirBlanc
     * http://localhost:8080/{$slug}/export/status:1/from:2020-01-01/to:2020-01-30
     *
     * Parâmetros to e from não são obrigatórios, caso não informado retorna todos os registros no status de pendentes
     *
     * Parâmetro status não é obrigatório, caso não informado retorna todos com status 1
     *
     */
    public function ALL_export()
    {
        $app = App::i();

        //Oportunidade que a query deve filtrar
        $opportunity_id = $this->data['opportunity'];
        $opportunity = $app->repo('Opportunity')->find($opportunity_id);

        $this->exportInit($opportunity);
        
        $registrations = $this->getRegistrations($opportunity);

        if (empty($registrations)) {
            echo "Não foram encontrados registros.";
            die();
        }

        $filename = $this->generateCSV($registrations);

        header('Content-Type: application/csv');
        header('Content-Disposition: attachment; filename=' . basename($filename));
        header('Pragma: no-cache');
        readfile($filename);
    }

    public function GET_import() {
        $this->requireAuthentication();

        $app = App::i();

        $opportunity_id = $this->data['opportunity'] ?? 0;

        $opportunity = $app->repo("Opportunity")->find($opportunity_id);

        $file_id = $this->data['file'] ?? 0;
        
        $config = $this->plugin->config;

        $is_opportunity_managed_handler = $config['is_opportunity_managed_handler'];

        if(!$is_opportunity_managed_handler($opportunity)){
            echo "A Oportunidade $opportunity_id não esta configurada para esse validador";
            die;
        }

        $opportunity = $app->repo('Opportunity')->find($opportunity_id);

        if (!$opportunity) {
            echo "Opportunidade de id $opportunity_id não encontrada";
            die;
        }

        $opportunity->checkPermission('@control');

        $files = $opportunity->getFiles($this->plugin->getSlug());
        
        foreach ($files as $file) {
            if ($file->id == $file_id) {
                $this->import($opportunity, $file->getPath());
            }
        }
    }

    /**
     * Importador para o inciso 1
     *
     * http://localhost:8080/{slug}/import/
     *
     */
    public function import(Opportunity $opportunity, string $filename)
    {

        /**
         * Seta o timeout e limite de memoria
         */
        ini_set('max_execution_time', 0);
        ini_set('memory_limit', '768M');


        //verifica se o mesmo esta no servidor
        if (!file_exists($filename)) {
            throw new Exception("Erro ao processar o arquivo. Arquivo inexistente");
        }

        $app = App::i();

        //Abre o arquivo em modo de leitura
        $stream = fopen($filename, "r");

        //Faz a leitura do arquivo
        $csv = Reader::createFromStream($stream);

        //Define o limitador do arqivo (, ou ;)
        $csv->setDelimiter(";");

        //Seta em que linha deve se iniciar a leitura
        $header_temp = $csv->setHeaderOffset(0);
        
        //Faz o processamento dos dados
        $stmt = (new Statement());
        $results = $stmt->process($csv);

        //Verifica a extenção do arquivo
        $ext = pathinfo($filename, PATHINFO_EXTENSION);

        if ($ext != "csv") {
            throw new Exception("Arquivo não permitido.");
        }
        
        $header_file = [];
        foreach ($header_temp as $key => $value) {
            $header_file[] = $value;
            break;
        }
        $required_columns = ['NUMERO', 'VALIDACAO', 'OBSERVACOES', 'DATA 1', 'VALOR 1'];

        $columns = '"' . implode('", "', $required_columns) . '"';
        foreach ($required_columns as $column) {
            if (!isset($header_file[0][$column])) {
                die("As colunas {$columns} são obrigatórias");
            }
        }

        $user = $this->plugin->getUser();

        $slug = $this->plugin->slug;
        $name = $this->plugin->name;
        
        $app->disableAccessControl();
        $count = 0;
        foreach ($results as $i => $line) {
            $num = $line['NUMERO'];
            $obs = $line['OBSERVACOES'];
            $eval = $line['VALIDACAO'];
            $column_status = (isset($line['STATUS']) && !empty($line['STATUS'])) ? $line['STATUS'] : null;
            $status = $this->getStatus($column_status);

            switch(strtolower($eval)){
                case 'aprovado':
                case 'aprovada':
                case 'selecionado':
                case 'selecionada':
                    $result = '10';
                break;

                case 'negada':
                case 'negado':
                case 'invalido':
                case 'inválido':
                case 'invalida':
                case 'inválida':
                    $result = '2';
                break;

                case 'não selecionado':
                case 'nao selecionado':
                case 'não selecionada':
                case 'nao selecionada':
                    $result = '3';
                break;
                
                case 'suplente':
                    $result = '8';
                break;
                
                default:
                    die("O valor da coluna VALIDACAO da linha $i está incorreto ($eval). Os valores possíveis são 'selecionada' ou 'aprovada', 'invalida', 'nao selecionada' ou 'suplente'");
                
            }

            if ($result == '10') {              
                
                for ($i = 1; $i <= 12; $i++) {
                    $data = $line["DATA {$i}"] ?? null;
                    $valor = $line["VALOR {$i}"] ?? null;
                    if ($data && $valor) {
                        $data = (new \DateTime($data))->format('d/m/Y');
                        $valor = number_format($valor, 2);
                        if(empty($obs)){
                            $obs = "Inscrição Aprovada\n------------------";                                               
                        }
                                                
                        $obs .= "\nR$ $valor a serem pagos em {$data}";    
                    }
                }
            }
            
            $registration = $app->repo('Registration')->findOneBy(['number' => $num]);

            if(!$registration){
                $app->log->debug($num. " Não encontrada");
                continue;
            }

            $registration->__skipQueuingPCacheRecreation = true;

            $raw_data = $registration->{$slug . '_raw'};
            $filesnames = $registration->{$slug . '_filename'};
            
            /* @TODO: implementar atualização de status?? */
            if (in_array($filename, $filesnames)) {
                $app->log->info("$name #{$count} {$registration} $eval - JÁ PROCESSADA");
                continue;
            }
            
            $mess = $column_status ?? $eval;

            $app->log->info("$name #{$count} {$registration} $mess");

            $raw_data[] = $line;
            $filesnames[] = $filename;

            $registration->{$slug . '_raw'} = $raw_data;
            $registration->{$slug . '_filename'} = $filesnames;

            $registration->save(true);
    
            $user = $this->plugin->user;
            

            /* @TODO: versão para avaliação documental */
            $evaluation = new RegistrationEvaluation;
            $evaluation->__skipQueuingPCacheRecreation = true;
            $evaluation->user = $user;
            $evaluation->registration = $registration;
            $evaluation->evaluationData = ['status' => $result, "obs" => $obs];
            $evaluation->result = $result;
            $evaluation->status = 1;

            $evaluation->save(true);

            for ($i = 1; $i <= 5; $i++) {
                $data = $line["DATA {$i}"] ?? null;
                $valor = $line["VALOR {$i}"] ?? null;
                if ($data && $valor) {
                    $payment = new Payment;
                    $payment->createdByUser = $this->plugin->getUser();
                    $payment->paymentDate = $data;
                    $payment->amount = str_replace(',','.',$valor);
                    $payment->registration = $registration;
                    $payment->metadata->csv_line = $line;
                    $payment->metadata->csv_filename = $filename;
                    $payment->status = $status;

                    $payment->save(true);
                }
            }

            $app->em->clear();
        }

        $app->enableAccessControl();

        // por causa do $app->em->clear(); não é possível mais utilizar a entidade para salvar
        $opportunity = $app->repo('Opportunity')->find($opportunity->id);

        $slug = $this->plugin->getSlug();

        $opportunity->refresh();
        $opportunity->name = $opportunity->name . ' ';
        $files = $opportunity->{$slug . '_processed_files'};
        $files->{basename($filename)} = date('d/m/Y \à\s H:i');
        $opportunity->{$slug . '_processed_files'} = $files;
        $opportunity->save(true);
        $this->finish('ok');
        

    }

    public function getStatus($value) {
        switch ($value) {
            case 'pago':
            case 'Pago':
            case 'PAGO':
            case 'paga':
            case 'Paga':
            case 'PAGA':
                $status = Payment::STATUS_PAID;
            break;

            case 'pendente':
            case 'Pendente':
            case 'PENDENTE':          
                $status = Payment::STATUS_PENDING;
            break;

            case 'PROCESSADO':
            case 'processado':
            case 'Processado':
            case 'processada':
            case 'Processada':
            case 'PROCESSADA':                     
                $status = Payment::STATUS_PROCESSING;
            break;

            case 'falha':
            case 'Falha':
                $status = Payment::STATUS_FAILED;
            break;
            
            case 'EXPORTADO':
            case 'EXPORTADA':
            case 'exportado':
            case 'Exportada':          
                $status = Payment::STATUS_EXPORTED;
            break;

            case 'DISPONIVEL':
            case 'Disponivel':
            case 'DISPONÍVEL':
            case 'Disponível':
            case 'disponível':
            case 'disponível':          
                $status = Payment::STATUS_AVAILABLE;
            break;
            
            default:
                $status = Payment::STATUS_PROCESSING;
                break;
        }
        
        return $status;
    }
}
