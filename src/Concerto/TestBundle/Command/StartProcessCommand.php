<?php

namespace Concerto\TestBundle\Command;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class StartProcessCommand extends Command
{

    const OS_WIN = 0;
    const OS_LINUX = 1;

    const SOURCE_PANEL_NODE = 0;
    const SOURCE_PROCESS = 1;
    const SOURCE_TEST_NODE = 2;
    const RESPONSE_VIEW_TEMPLATE = 0;
    const RESPONSE_FINISHED = 1;
    const RESPONSE_SUBMIT = 2;
    const RESPONSE_STOP = 3;
    const RESPONSE_STOPPED = 4;
    const RESPONSE_VIEW_FINAL_TEMPLATE = 5;
    const RESPONSE_RESULTS = 7;
    const RESPONSE_AUTHENTICATION_FAILED = 8;
    const RESPONSE_STARTING = 9;
    const RESPONSE_KEEPALIVE_CHECKIN = 10;
    const RESPONSE_UNRESUMABLE = 11;
    const RESPONSE_SESSION_LIMIT_REACHED = 12;
    const RESPONSE_WORKER = 15;
    const RESPONSE_ERROR = -1;
    const STATUS_RUNNING = 0;
    const STATUS_STOPPED = 1;
    const STATUS_FINALIZED = 2;
    const STATUS_ERROR = 3;
    const STATUS_REJECTED = 4;

    private $panelNode;
    private $lastClientTime;    //for idle timeout
    private $lastKeepAliveTime; //for keep alive timeout
    private $maxIdleTime;
    private $keepAliveIntervalTime;
    private $keepAliveToleranceTime;
    private $isSerializing;
    private $isDebug;
    private $debugOffset;
    private $logPath;
    private $rLogPath;
    private $rEnviron;

    /**
     * @var OutputInterface $output
     */
    private $output;

    /**
     * @var LoggerInterface $logger
     */
    private $logger;
    private $testRunnerSettings;

    public function __construct(LoggerInterface $logger, $testRunnerSettings)
    {
        parent::__construct();

        $this->logger = $logger;
        $this->testRunnerSettings = $testRunnerSettings;
    }

    protected function configure()
    {
        $this->isSerializing = false;
        $this->isDebug = false;
        $this->debugOffset = 0;
        $this->setName("concerto:r:start")->setDescription("Starts new R session.");
        $this->addArgument("ini_path", InputArgument::REQUIRED, "initialization file path");
        $this->addArgument("test_node", InputArgument::REQUIRED, "test node json serialized data");
        $this->addArgument("panel_node", InputArgument::REQUIRED, "panel node json serialized data");
        $this->addArgument("test_session_id", InputArgument::REQUIRED, "test session id");
        $this->addArgument("panel_node_connection", InputArgument::REQUIRED, "panel node connection json serialized data");
        $this->addArgument("client", InputArgument::REQUIRED, "client json serialized data");
        $this->addArgument("working_directory", InputArgument::REQUIRED, "session working directory");
        $this->addArgument("public_directory", InputArgument::REQUIRED, "public directory");
        $this->addArgument("media_url", InputArgument::REQUIRED, "media URL");
        $this->addArgument("log_path", InputArgument::REQUIRED, "log path");
        $this->addArgument("debug", InputArgument::REQUIRED, "debug test execution");
        $this->addArgument("keep_alive_interval_time", InputArgument::REQUIRED, "keep-alive interval time");
        $this->addArgument("keep_alive_tolerance_time", InputArgument::REQUIRED, "keep-alive tolerance time");
        $this->addArgument("submit", InputArgument::OPTIONAL, "submitted variables");

        $this->addOption("r_environ", "renv", InputOption::VALUE_OPTIONAL, "R Renviron file path", null);
    }

    private function log($fun, $msg = null, $error = false)
    {

        $this->output->write("[" . date("Y-m-d H:i:s") . "] " . $fun . ($msg ? " - $msg" : ""), true);
        if ($error) {
            $this->logger->error(__CLASS__ . ":" . $fun . " " . $msg);
        }
    }

    private function createListenerSocket()
    {
        $this->log(__FUNCTION__);

        if (($sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP)) === false) {
            $this->log(__FUNCTION__, "socket_create() failed, listener socket, " . socket_strerror(socket_last_error()), true);
            return false;
        }
        if (socket_bind($sock, "0.0.0.0") === false) {
            $this->log(__FUNCTION__, "socket_bind() failed, listener socket, " . socket_strerror(socket_last_error($sock)), true);
            socket_close($sock);
            return false;
        }
        if (socket_listen($sock, SOMAXCONN) === false) {
            $this->log(__FUNCTION__, "socket_listen() failed, listener socket, " . socket_strerror(socket_last_error($sock)), true);
            socket_close($sock);
            return false;
        }
        socket_set_nonblock($sock);
        return $sock;
    }

    private function createPanelNodeResponseSocket()
    {
        $this->log(__FUNCTION__);

        if (($sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP)) === false) {
            $this->log(__FUNCTION__, "socket_create() failed, response socket, " . socket_strerror(socket_last_error()), true);
            return false;
        }
        if (socket_connect($sock, gethostbyname($this->panelNode->sock_host), $this->panelNode->port) === false) {
            $this->log(__FUNCTION__, "socket_connect() failed, response socket, " . socket_strerror(socket_last_error($sock)), true);
            socket_close($sock);
            return false;
        }
        return $sock;
    }

    private function startListener($server_sock, $submitter_sock)
    {
        $this->log(__FUNCTION__);

        $this->lastClientTime = time();
        $this->lastKeepAliveTime = time();
        do {
            if ($this->checkIdleTimeout($submitter_sock) || $this->checkKeepAliveTimeout($submitter_sock)) {
                break;
            }
            if (($client_sock = @socket_accept($server_sock)) === false) {
                continue;
            }

            $this->log(__FUNCTION__, "socket accepted");

            if (false === ($buf = socket_read($client_sock, 8388608, PHP_NORMAL_READ))) {
                continue;
            }
            if (!$msg = trim($buf)) {
                continue;
            }

            $this->log(__FUNCTION__, $msg);
            if ($this->interpretMessage($submitter_sock, $msg)) {
                break;
            }
        } while (usleep(100 * 1000) || true);

        $this->log(__FUNCTION__, "listener ended");
    }

    private function stopProcess($submitter_sock)
    {
        $this->isSerializing = true;
        $this->respondToProcess($submitter_sock, json_encode(array(
            "source" => self::SOURCE_TEST_NODE,
            "code" => self::RESPONSE_STOP
        )));
    }

    private function checkIdleTimeout($submitter_sock)
    {
        if (time() - $this->lastClientTime > $this->maxIdleTime && !$this->isSerializing) {
            $this->log(__FUNCTION__, "idle timeout reached");

            $this->stopProcess($submitter_sock);
            return true;
        } else {
            return false;
        }
    }

    private function checkKeepAliveTimeout($submitter_sock)
    {
        if ($this->keepAliveIntervalTime > 0 && time() - $this->lastKeepAliveTime > $this->keepAliveIntervalTime + $this->keepAliveToleranceTime && !$this->isSerializing) {
            $this->log(__FUNCTION__, "keep alive timeout reached");

            $this->stopProcess($submitter_sock);
            return true;
        } else {
            return false;
        }
    }

    private function interpretMessage($submitter_sock, $message)
    {
        $this->log(__FUNCTION__, $message);

        $msg = json_decode($message);
        switch ($msg->source) {
            case self::SOURCE_PROCESS:
                {
                    return $this->interpretProcessMessage($message);
                }
            case self::SOURCE_PANEL_NODE:
                {
                    return $this->interpretPanelNodeMessage($submitter_sock, $message);
                }
        }
    }

    private function interpretPanelNodeMessage($submitter_sock, $message)
    {
        $this->log(__FUNCTION__, $message);

        $msg = json_decode($message);
        switch ($msg->code) {
            case self::RESPONSE_SUBMIT:
                {
                    $this->panelNode = $msg->panelNode;
                    $this->lastClientTime = time();
                    $this->lastKeepAliveTime = time();
                    $this->respondToProcess($submitter_sock, $message);
                    return false;
                }
            case self::RESPONSE_WORKER:
                {
                    $this->panelNode = $msg->panelNode;
                    $this->lastKeepAliveTime = time();
                    $this->respondToProcess($submitter_sock, $message);
                    return false;
                }
            case self::RESPONSE_KEEPALIVE_CHECKIN:
                {
                    $this->lastKeepAliveTime = time();
                    return false;
                }
            case self::RESPONSE_STOP:
                {
                    $this->stopProcess($submitter_sock);
                    return true;
                }
        }
    }

    private function interpretProcessMessage($message)
    {
        $this->log(__FUNCTION__, $message);

        $msg = json_decode($message, true);
        switch ($msg["code"]) {
            case self::RESPONSE_VIEW_TEMPLATE:
                {
                    if (!$this->respondToPanelNode($message)) return true;
                    return false;
                }
            case self::RESPONSE_WORKER:
                {
                    if (!$this->respondToPanelNode($message)) return true;
                    return false;
                }
            case self::RESPONSE_UNRESUMABLE:
            case self::RESPONSE_ERROR:
            case self::RESPONSE_FINISHED:
            case self::RESPONSE_VIEW_FINAL_TEMPLATE:
                {
                    $this->respondToPanelNode($message);
                    return true;
                }
            case self::RESPONSE_STOPPED:
                {
                    return true;
                }
        }
    }

    private function respondToProcess($submitter_sock, $response)
    {
        $this->log(__FUNCTION__, $response);

        do {
            if (($client_sock = socket_accept($submitter_sock)) === false) {
                continue;
            }

            $this->log(__FUNCTION__, "socket accepted");

            socket_write($client_sock, $response . "\n");
            break;
        } while (usleep(100 * 1000) || true);
        $this->log(__FUNCTION__, "submitter ended");
    }

    private function respondToPanelNode($response)
    {
        $this->log(__FUNCTION__);

        if ($this->isDebug) {
            $response = $this->appendDebugDataToResponse($response);
        }

        $this->log(__FUNCTION__, $response);

        $resp_sock = $this->createPanelNodeResponseSocket();
        if ($resp_sock) {
            socket_write($resp_sock, $response . "\n");
            socket_close($resp_sock);
            return true;
        }
        return false;
    }

    private function appendDebugDataToResponse($response)
    {
        if (file_exists($this->rLogPath)) {
            $new_data = file_get_contents($this->rLogPath, false, null, $this->debugOffset);
            $this->debugOffset += strlen($new_data);
            $decoded_response = json_decode($response, true);
            $decoded_response["debug"] = mb_convert_encoding($new_data, "UTF-8");
            $response = json_encode($decoded_response);
        }
        return $response;
    }

    //TODO proper OS detection
    private function getOS()
    {
        if (strpos(strtolower(PHP_OS), "win") !== false) {
            return self::OS_WIN;
        } else {
            return self::OS_LINUX;
        }
    }

    private function escapeWindowsArg($arg)
    {
        $arg = addcslashes($arg, '"');
        $arg = str_replace("(", "^(", $arg);
        $arg = str_replace(")", "^)", $arg);
        return $arg;
    }

    private function getCommand($rscript_exec, $ini_path, $panel_node_connection, $test_node, $submitter, $client, $test_session_id, $wd, $pd, $murl, $values, $max_exec_time)
    {
        switch ($this->getOS()) {
            case self::OS_LINUX:
                return "nohup " . $rscript_exec . " --no-save --no-restore --quiet "
                    . "'$ini_path' "
                    . "'$panel_node_connection' "
                    . "'$test_node' "
                    . "'$submitter' "
                    . "'$client' "
                    . "$test_session_id "
                    . "'$wd' "
                    . "'$pd' "
                    . "'$murl' "
                    . "$max_exec_time "
                    . "'$values' "
                    . ">> "
                    . "'" . $this->logPath . "' "
                    . "> "
                    . "'" . $this->rLogPath . "' "
                    . "2>&1 & echo $!";
            default:
                return "start cmd /C \""
                    . "\"" . $this->escapeWindowsArg($rscript_exec) . "\" --no-save --no-restore --quiet "
                    . "\"" . $this->escapeWindowsArg($ini_path) . "\" "
                    . "\"" . $this->escapeWindowsArg($panel_node_connection) . "\" "
                    . "\"" . $this->escapeWindowsArg($test_node) . "\" "
                    . "\"" . $this->escapeWindowsArg($submitter) . "\" "
                    . "\"" . $this->escapeWindowsArg($client) . "\" "
                    . "$test_session_id "
                    . "\"" . $this->escapeWindowsArg($wd) . "\" "
                    . "\"" . $this->escapeWindowsArg($pd) . "\" "
                    . "$murl "
                    . "$max_exec_time "
                    . "\"" . ($values ? $this->escapeWindowsArg($values) : "{}") . "\" "
                    . ">> "
                    . "\"" . $this->escapeWindowsArg($this->logPath) . "\" "
                    . "> "
                    . "\"" . $this->escapeWindowsArg($this->rLogPath) . "\" "
                    . "2>&1\"";
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->output = $output;
        $this->log(__FUNCTION__);

        $rscript_exec = $this->testRunnerSettings["rscript_exec"];
        $panel_node = $input->getArgument("panel_node");
        $this->panelNode = json_decode($panel_node);
        $panel_node_connection = $input->getArgument("panel_node_connection");
        $client = $input->getArgument("client");
        $ini_path = $input->getArgument("ini_path");
        $test_session_id = $input->getArgument("test_session_id");
        $wd = $input->getArgument("working_directory");
        $pd = $input->getArgument("public_directory");
        $murl = $input->getArgument("media_url");
        $this->logPath = $input->getArgument("log_path");
        $this->rLogPath = $this->logPath . ".r";
        $values = $input->getArgument("submit");
        $this->isDebug = $input->getArgument("debug") == 1;
        if (!$values) {
            $values = "";
        }
        $max_exec_time = $this->testRunnerSettings["max_execution_time"];
        $this->maxIdleTime = $this->testRunnerSettings["max_idle_time"];
        $this->keepAliveIntervalTime = $input->getArgument("keep_alive_interval_time");
        $this->keepAliveToleranceTime = $input->getArgument("keep_alive_tolerance_time");
        $this->rEnviron = $input->getOption("r_environ");

        $test_node = $input->getArgument("test_node");

        $test_node_sock = $this->createListenerSocket();
        if ($test_node_sock === false) {
            $this->respondToPanelNode(json_encode(array(
                "source" => self::SOURCE_TEST_NODE,
                "code" => self::RESPONSE_ERROR
            )));
            $this->log(__FUNCTION__, "creating listener socket for test node failed; prematurely closing process");
            return 1;
        }

        socket_getsockname($test_node_sock, $test_node_ip, $test_node_port);
        $decoded_test_node = json_decode($test_node, true);
        $decoded_test_node["port"] = $test_node_port;
        $test_node = json_encode($decoded_test_node);

        $submitter_sock = $this->createListenerSocket();
        if ($submitter_sock === false) {
            $this->respondToPanelNode(json_encode(array(
                "source" => self::SOURCE_TEST_NODE,
                "code" => self::RESPONSE_ERROR
            )));
            socket_close($test_node_sock);
            socket_close($submitter_sock);
            $this->log(__FUNCTION__, "creating listener socket for submitter failed; prematurely closing process");
            return 1;
        }

        socket_getsockname($submitter_sock, $submitter_ip, $submitter_port);
        $submitter = json_encode(array("host" => $submitter_ip, "port" => $submitter_port));

        //@TODO values possibly not needed to be passed here
        $success = false;
        if ($this->getOS() == self::OS_LINUX && $this->testRunnerSettings["session_forking"] == "true") {
            $success = $this->childProcess($panel_node_connection, $test_node, $submitter, $client, $test_session_id, $wd, $pd, $murl, $values, $max_exec_time);
        } else {
            $success = $this->standaloneProcess($rscript_exec, $ini_path, $panel_node_connection, $test_node, $submitter, $client, $test_session_id, $wd, $pd, $murl, $values, $max_exec_time);
        }
        if (!$success) {
            $this->respondToPanelNode(json_encode(array(
                "source" => self::SOURCE_TEST_NODE,
                "code" => self::RESPONSE_ERROR
            )));
            socket_close($test_node_sock);
            socket_close($submitter_sock);
            $this->log(__FUNCTION__, "creating child process failed; prematurely closing process");
            return 1;
        }

        $this->startListener($test_node_sock, $submitter_sock);
        socket_close($submitter_sock);
        socket_close($test_node_sock);
        $this->log(__FUNCTION__, "closing process");
    }

    protected function standaloneProcess($rscript_exec, $ini_path, $panel_node_connection, $test_node, $submitter, $client, $test_session_id, $wd, $pd, $murl, $values, $max_exec_time)
    {
        $cmd = $this->getCommand($rscript_exec, $ini_path, $panel_node_connection, $test_node, $submitter, $client, $test_session_id, $wd, $pd, $murl, $values, $max_exec_time);

        $this->log(__FUNCTION__, $cmd);

        $process = new Process($cmd);
        $process->setEnhanceWindowsCompatibility(false);
        if ($this->rEnviron != null) {
            $this->log(__FUNCTION__, "setting process renviron to: " . $this->rEnviron);
            $env = array();
            $env["R_ENVIRON"] = $this->rEnviron;
            $process->setEnv($env);
        }
        $process->mustRun();
        return true;
    }

    protected function childProcess($panel_node_connection, $test_node, $submitter, $client, $test_session_id, $wd, $pd, $murl, $values, $max_exec_time)
    {
        $response = json_encode(array(
            "workingDir" => realpath($wd),
            "maxExecTime" => $max_exec_time,
            "testNode" => json_decode($test_node, true),
            "client" => json_decode($client, true),
            "submitter" => json_decode($submitter, true),
            "connection" => json_decode($panel_node_connection, true),
            "sessionId" => $test_session_id,
            "rLogPath" => $this->rLogPath
        ));

        $fh = fopen("/usr/src/concerto/src/Concerto/TestBundle/Resources/R/forker.fifo", "wt");
        if ($fh === false) {
            $this->log(__FUNCTION__, "fopen() failed", true);
            return false;
        }
        $buffer = $response . "\n";
        $sent = fwrite($fh, $buffer);
        $success = $sent !== false;
        if (!$success) {
            $this->log(__FUNCTION__, "fwrite() failed", true);
        }
        if (strlen($buffer) != $sent) {
            $this->log(__FUNCTION__, "fwrite() failed, sent only $sent/" . strlen($buffer), true);
            $success = false;
        }
        fclose($fh);
        return $success;
    }
}
