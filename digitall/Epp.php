<?php

namespace digitall;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use RuntimeException;
use SimpleXMLElement;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use Twig\Loader\FilesystemLoader;

/**
 * Class Epp
 */
class Epp
{
    /**
     * Result code of a response with a successful completed operation
     */
    private const RESPONSE_COMPLETED_SUCCESS = 1000;
    /**
     * Result code of a response with an authorization error
     */
    private const RESPONSE_FAILED_AUTH_ERROR = 2200;
    /**
     * Result code of a response from a successful logout request
     */
    private const RESPONSE_COMPLETED_END_SESSION = 1500;
    /**
     * Result code of a response to a misconfigured command
     */
    private const RESPONSE_FAILED_COMMAND_USE_ERROR = 2002;
    /**
     * Result code of a response with a login request to a server in a too short period of time
     */
    const RESPONSE_FAILED_SESSION_LIMIT_EXCEEDED = 2502;
    /**
     * Result code of a response to a command with a syntax error
     */
    const RESPONSE_FAILED_SYNTAX_ERROR = 2001;
    /**
     * Result code of a response to a command with a required parameter missing
     */
    const RESPONSE_FAILED_REQUIRED_PARAMETER_MISSING = 2003;
    /**
     * Result code of a response to a command with a parameter outside the value range
     */
    const RESPONSE_FAILED_PARAMETER_VALUE_RANGE = 2004;
    /**
     * Result code of a response to a command to change a contact or domain that does not belong to the Registrar
     */
    const RESPONSE_COMPLETED_AUTH_ERROR = 2201;
    /**
     * Result code of a response to a request to use a contact or domain that does not exist on the server
     */
    const RESPONSE_COMPLETED_OBJECT_DOES_NOT_EXIST = 2303;
    /**
     * @var Client GuzzleHTTP client
     */
    private $client;
    /**
     * @var Environment Twig template system
     */
    private $tpl;
    /**
     * @var string|null Client transaction ID
     */
    private $clTRID;

    /**
     * Class constructor
     *
     * @param $name string Name of the Epp instance for the logs
     * @param $credentials array username, password and server
     */
    public function __construct($name, $credentials)
    {

        try {
            $this->log = new Logger('log-' . $name);
            //ErrorHandler::register($this->log);
            $this->log->pushHandler(new StreamHandler(__DIR__ . '/logs/debug-' . $name . '.log', Logger::DEBUG));
            $this->log->pushHandler(new StreamHandler(__DIR__ . '/logs/info-' . $name . '.log', Logger::INFO));
            $this->log->pushHandler(new StreamHandler(__DIR__ . '/logs/warning-' . $name . '.log', Logger::WARNING));
            $this->log->pushHandler(new StreamHandler(__DIR__ . '/logs/error-' . $name . '.log', Logger::ERROR));
        } catch (Exception $e) {
            die('Cannot instantiate logger:' . $e->getMessage());
        }

        $this->credentials = $credentials;

        $this->client = new Client([
            'base_uri' => $this->credentials['uri'],
            'verify' => false,
            'cookies' => true
        ]);

        $tpl_loader = new FilesystemLoader(__DIR__ . '/xml');
        $tpl_cfg = array();
        //$tpl_cfg['cache'] = __DIR__ . '/cache';
        $this->tpl = new Environment($tpl_loader, $tpl_cfg);

        $this->log->info('EPP client created');
    }

    /**
     * Class destructor
     */
    public function __destruct()
    {
        $this->log->info('EPP client destroyed');
    }

    /**
     * Transmit an hello message, helps to mantain connection and receive server parameters
     */
    public function hello(): void
    {

        $response = $this->send($this->render('hello'));
        $this->log->info('hello sent');

        if ($this->nodeExists($response->greeting)) {
            $this->log->info('Received a greeting after hello');
        } else {
            $this->log->error("No greeting after hello");
            throw new RuntimeException('No greeting after hello');
        }

    }

    /**
     * Sends a message to the server, receives and parses the response to a structure
     *
     * @param string $xml Message to transmit
     * @return SimpleXMLElement Structure to return
     */
    private function send(string $xml, bool $debug = false): SimpleXMLElement
    {
        try {
            /* @var $client Client */
            if (isset($xml)) {
                $res = $this->client->request('POST', '',
                    [
                        'body' => $xml
                    ]);
            } else {
                throw new \http\Exception\RuntimeException('Empty request');
            }
        } catch (GuzzleException $e) {
            throw new RuntimeException('Error during transmission: ' . $e->getMessage());
        }
        if ($debug) echo($res->getBody());
        return simplexml_load_string($res->getBody());
    }

    /**
     * Renders a message from a template using the given parameters
     *
     * @param $template string Name of the template to use for rendering
     * @param array $vars Parameters to fill the template with
     * @return string XML Code generated
     */
    public function render($template, array $vars = []): string
    {
        $vars['clTRID'] = $this->set_clTRID('DGT');

        try {
            $xml = $this->tpl->render($template . '.twig', $vars);
        } catch (LoaderError $e) {
            throw new RuntimeException('Cannot load template file during rendering of ' . $template . ' template: ' . $e->getMessage());
        } catch (RuntimeError $e) {
            throw new RuntimeException('Runtime error during rendering of ' . $template . ' template: ' . $e->getMessage());
        } catch (SyntaxError $e) {
            throw new RuntimeException('Syntax error during rendering of ' . $template . ' template: ' . $e->getMessage());
        }
        return $xml;
    }

    /**
     * Generate a client transaction ID unique to each transmission, to send with the message
     *
     * @param $prefix string Text to attach at the start of the string
     * @return string Generated text
     */
    public function set_clTRID($prefix)
    {
        $this->clTRID = $prefix . "-" . time() . "-" . substr(md5(mt_rand()), 0, 5);
        if (strlen($this->clTRID) > 32)
            $this->clTRID = substr($this->clTRID, -32);
        return $this->clTRID;
    }

    /**
     * Helper function that checks if a node exists in an XML structure
     *
     * @param $obj SimpleXMLElement XML node to check
     * @return bool Result of the check
     */
    public function nodeExists($obj): bool
    {
        return (is_countable($obj)) && (count($obj) !== 0);
    }

    /**
     * Login message
     * Can also change the password with an optional second parameter
     * Can use a specific password  instead of the configured one if given the optional third parameter
     *
     * @param string $newpassword New password to use
     * @param string $testpassword Current password to use, overrides config
     */
    public function login($newpassword = null, $testpassword = null): void
    {
        $vars = [
            'clID' => $this->credentials['username'],
            'pw' => $this->credentials['password']
        ];

        if ($newpassword !== null) $vars['newPW'] = $newpassword;

        if ($testpassword !== null) $vars['pw'] = $testpassword;


        $xml = $this->render('login', $vars);
        $response = $this->send($xml);
        $this->log->info('login sent');

        if ($this->nodeExists($response->response)) {
            $code = $response->response->result["code"];
            $this->log->info('Received a response to login: ' . $response->response->result->msg);

            $this->handleReturnCode('Session start', (int)$code);

            if (
                self::RESPONSE_COMPLETED_SUCCESS == $code &&
                $newpassword !== null
            ) {
                $this->log->info('Password changed ' . (($testpassword !== null) ? 'back ' : '') . 'to "' . $newpassword . '"');
            }

        } else {
            $this->log->error("No response to login");
            throw new RuntimeException('No response to login');
        }
    }

    /**
     * Logs a secondary reason that comes with certain error codes
     *
     * @param $msg string Description of the message sent to the server
     * @param $reason string Secondary text to log
     */
    private function logReason($msg, $reason)
    {
        if ($reason !== null) $this->log->err($msg . ' - Reason:' . $reason);
    }

    /**
     * Logs formatted messages regarding common communication problems
     *
     * @param  $msg string Description of the message sent to the server
     * @param int $code Error code
     * @param string|null $reason Optional secondary message that explains more
     */
    private function handleReturnCode($msg, int $code, $reason = null): void
    {
        switch ($code) {
            case self::RESPONSE_COMPLETED_AUTH_ERROR:
                $this->log->warn($msg . ' - Authorization error.');
                $this->logReason($msg, $reason);
                //throw new RuntimeException ($msg . ' - Wrong credentials.' . $reason);
                break;
            case self::RESPONSE_FAILED_AUTH_ERROR:
                $this->log->err($msg . ' - Wrong credentials.');
                $this->logReason($msg, $reason);
                throw new RuntimeException ($msg . ' - Wrong credentials.' . $reason);
                break;
            case self::RESPONSE_FAILED_SESSION_LIMIT_EXCEEDED:
                $this->log->err($msg . ' - Session limit exceeded, server closing connection. Try again later.');
                $this->logReason($msg, $reason);
                throw new RuntimeException ($msg . ' - Session limit exceeded, server closing connection. Try again later.' . $reason);
                break;
            case self::RESPONSE_COMPLETED_END_SESSION:
                $this->log->info($msg . ' - Session ended.');
                break;
            case self::RESPONSE_COMPLETED_SUCCESS:
                $this->log->info($msg . ' - Success.');
                break;
            case self::RESPONSE_FAILED_COMMAND_USE_ERROR:
                $this->log->err($msg . ' - Command use error.');
                $this->logReason($msg, $reason);
                $this->logout(); // Prevent session limit
                throw new RuntimeException($msg . ' - Command use error.' . $reason);
                break;
            case self::RESPONSE_FAILED_SYNTAX_ERROR:
                $this->log->err($msg . ' - Syntax error.');
                $this->logReason($msg, $reason);
                $this->logout(); // Prevent session limit
                throw new RuntimeException($msg . ' - Syntax error.' . $reason);
                break;
            case self::RESPONSE_FAILED_PARAMETER_VALUE_RANGE:
                $this->log->err($msg . ' - Syntax error.');
                $this->logReason($msg, $reason);
                $this->logout(); // Prevent session limit
                throw new RuntimeException($msg . ' - Syntax error.' . $reason);
                break;
            case self::RESPONSE_FAILED_REQUIRED_PARAMETER_MISSING:
                $this->log->err($msg . ' - Required parameter missing.');
                $this->logReason($msg, $reason);
                $this->logout(); // Prevent session limit
                throw new RuntimeException($msg . ' - Required parameter missing.' . $reason);
                break;
            case self::RESPONSE_COMPLETED_OBJECT_DOES_NOT_EXIST:
                break;
            default:
                $this->log->err($msg . ' - Unhandled return code ' . $code);
                $this->logout(); // Prevent session limit
                throw new RuntimeException($msg . ' - Unhandled return code ' . $code . '.' . $reason);
                break;
        }
    }

    /**
     * Logs out from the server
     */
    public function logout(): void
    {
        $xml = $this->render('logout', ['clTRID' => $this->clTRID]);
        $response = $this->send($xml);
        $this->log->info('logout sent');

        if ($this->nodeExists($response->response)) {
            $code = $response->response->result["code"];

            $this->log->info('Received a response to logout: ' . $response->response->result->msg);

            $this->handleReturnCode('Session end', (int)$code);

        } else {
            $this->log->error("No response to logout");
            throw new RuntimeException('No response to logout');
        }
    }

    /**
     * Check availability of a list of contacts
     *
     * @param $contacts array List of contact handles to check
     * @return array Result of the check as an array with an availability boolean for each contact handle
     */
    public function contactsCheck($contacts): array
    {
        $availability = [];

        $xml = $this->render('contact-check', ['contacts' => $contacts]);
        $response = $this->send($xml);
        $handles = "";
        foreach ($contacts as $contact) $handles .= '"' . $contact["handle"] . '", ';
        $handles = rtrim($handles, ', ');
        $this->log->info('contact check sent for ' . $handles);

        if ($this->nodeExists($response->response)) {
            $code = $response->response->result["code"];
            $this->log->info('Received a response to contact check: ' . $response->response->result->msg);

            $this->handleReturnCode('Contact check', (int)$code);
            if (self::RESPONSE_COMPLETED_SUCCESS == $code) {
                $ns = $response->getNamespaces(true);
                /* @var $contacts_checked SimpleXMLElement */
                /* @var $response SimpleXMLElement */
                $contacts_checked = $response->response->resData->children($ns['contact'])->chkData->cd;
                $logstring = '';
                foreach ($contacts_checked as $contact) {
                    $handle = (string)$contact->id;
                    $avail = (bool)$contact->id->attributes()->avail;
                    $availability[$handle] = $avail;
                    $logstring .= '"' . $handle . '" is ' . ($avail ? '' : 'not ') . 'available, ';
                }
                $logstring = rtrim($logstring, ', ') . '.';
                $this->log->info($logstring);
            }
        } else {
            $this->log->error("No response to logout");
            throw new RuntimeException('No response to logout');
        }

        return $availability;
    }

    /**
     * Creates a contact
     *
     * @param array $contact Details of the contact
     */
    public function contactCreate(array $contact): void
    {
        $xml = $this->render('contact-create', ['contact' => $contact]);
        $response = $this->send($xml);

        $this->log->info('contact create sent for "' . $contact['handle'] . '"');

        if ($this->nodeExists($response->response)) {
            $code = $response->response->result["code"];
            $this->log->info('Received a response to contact create: ' . $response->response->result->msg);
            $reason = ($this->nodeExists($response->response->result->extValue->reason)) ? $response->response->result->extValue->reason : null;
            $this->handleReturnCode('Contact create', (int)$code, $reason);

        } else {
            $this->log->error('No response to contact create for "' . $contact['handle'] . '"');
            throw new RuntimeException('No response to contact create for "' . $contact['handle'] . '"');
        }
    }

    /**
     *
     * @param array $contact
     */
    public function contactUpdate(array $contact): void
    {

        $xml = $this->render('contact-update', ['contact' => $contact]);

        $response = $this->send($xml);

        $this->log->info('contact update sent for "' . $contact['handle'] . '"');

        if ($this->nodeExists($response->response)) {
            $code = $response->response->result["code"];
            $this->log->info('Received a response to contact update: ' . $response->response->result->msg);
            $reason = ($this->nodeExists($response->response->result->extValue->reason)) ? $response->response->result->extValue->reason : null;
            $this->handleReturnCode('Contact update', (int)$code, $reason);

        } else {
            $this->log->error('No response to contact update for "' . $contact['handle'] . '"');
            throw new RuntimeException('No response to contact update for "' . $contact['handle'] . '"');
        }

    }

    /**
     * @param $handle
     * @return array
     */
    public function contactGetInfo($handle)
    {
        $xml = $this->render('contact-info', ['handle' => $handle]);

        $response = $this->send($xml);

        $this->log->info('contact info sent for "' . $handle . '"');

        if ($this->nodeExists($response->response)) {
            $code = $response->response->result["code"];
            $this->log->info('Received a response to contact info: ' . $response->response->result->msg);
            $reason = ($this->nodeExists($response->response->result->extValue->reason)) ? $response->response->result->extValue->reason : null;
            $this->handleReturnCode('Contact info', (int)$code, $reason);

            $return = [
                'code' => (int)$code
            ]; // all non blocking codes are returned: success, auth_error, object does not exist


            if (self::RESPONSE_COMPLETED_SUCCESS == $code) {
                $ns = $response->getNamespaces(true);
                $return['contact'] = $response->response->resData->children($ns['contact'])->infData;
            };

            return $return;

        }

        $this->log->error('No response to contact info for "' . $handle . '"');
        throw new RuntimeException('No response to contact update for "' . $handle . '"');
    }

    /**
     * @param $domains
     * @return array
     */
    public function domainsCheck($domains)
    {
        $availability = [];

        $xml = $this->render('domain-check', ['domains' => $domains]);
        $response = $this->send($xml);
        $names = "";
        foreach ($domains as $domain) $names .= '"' . $domain["name"] . '", ';
        $names = rtrim($names, ', ');
        $this->log->info('domain check sent for ' . $names);

        if ($this->nodeExists($response->response)) {
            $code = $response->response->result["code"];
            $this->log->info('Received a response to domain check: ' . $response->response->result->msg);

            $this->handleReturnCode('domain check', (int)$code);
            if (self::RESPONSE_COMPLETED_SUCCESS == $code) {
                $ns = $response->getNamespaces(true);
                $domains = $response->response->resData->children($ns['domain'])->chkData->cd;
                $logstring = '';
                foreach ($domains as $domain) {
                    $name = (string)$domain->name;
                    $avail = (bool)$domain->name->attributes()->avail;

                    $return =
                        ['avail' => $avail];
                    $logstring .= '"' . $name . '" is ' . ($avail ? '' : 'not ') . 'available, ';

                    if (!$avail) {
                        $reason = $domain->reason;
                        $return['reason'] = $reason;
                        $logstring = rtrim($logstring, ', ') . '(reason is:' . $reason . '), ';
                    }

                    $availability[$name] = $return;


                }
                $logstring = rtrim($logstring, ', ') . '.';
                $this->log->info($logstring);
            }
        } else {
            $this->log->error("No response to logout");
            throw new RuntimeException('No response to logout');
        }

        return $availability;
    }

    /**
     * @param array $domain
     */
    public function domainCreate(array $domain)
    {
        $xml = $this->render('domain-create', ['domain' => $domain]);
        $response = $this->send($xml);

        $this->log->info('domain create sent for "' . $domain['name'] . '"');

        if ($this->nodeExists($response->response)) {
            $code = $response->response->result["code"];
            $this->log->info('Received a response to domain create: ' . $response->response->result->msg);
            $reason = ($this->nodeExists($response->response->result->extValue->reason)) ? $response->response->result->extValue->reason : null;
            $this->handleReturnCode('domain create', (int)$code, $reason);

        } else {
            $this->log->error('No response to domain create for "' . $domain['handle'] . '"');
            throw new RuntimeException('No response to domain create for "' . $domain['handle'] . '"');
        }
    }
}