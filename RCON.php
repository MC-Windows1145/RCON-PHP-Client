<?php

/**
 * RCON 控制台工具 for PHP
 * 兼容 PHP 7.0.x
 * 直接用PM的BIN也可以
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

class RCONClient {
    private $host;
    private $port;
    private $password;
    private $timeout;
    private $socket;
    private $connected = false;
    
    // 数据包类型
    const PACKET_TYPE_COMMAND = 2;
    const PACKET_TYPE_AUTH = 3;
    
    public function __construct($host = "127.0.0.1", $port = 19132, $password = "", $timeout = 5) {
        $this->host = $host;
        $this->port = (int)$port;
        $this->password = $password;
        $this->timeout = (int)$timeout;
    }
    
    public function connect() {
        $this->socket = @fsockopen($this->host, $this->port, $errno, $errstr, $this->timeout);
        
        if (!$this->socket) {
            throw new Exception("无法连接到服务器 {$this->host}:{$this->port}: $errstr (错误代码: $errno)");
        }
        
        stream_set_timeout($this->socket, $this->timeout);
        $this->connected = true;
        
        return $this->authenticate();
    }
    
    private function authenticate() {
        $packet = $this->createPacket(self::PACKET_TYPE_AUTH, $this->password);
        if (fwrite($this->socket, $packet) === false) {
            throw new Exception("发送认证数据失败");
        }
        
        $response = fread($this->socket, 4096);
        if ($response === false || strlen($response) < 12) {
            throw new Exception("认证响应无效");
        }
        
        $header = $this->parsePacketHeader($response);
        
        if ($header['id'] == -1) {
            throw new Exception("RCON认证失败: 密码错误");
        }
        
        return true;
    }
    
    public function sendCommand($command) {
        if (!$this->connected) {
            throw new Exception("未连接到服务器");
        }
        
        $packet = $this->createPacket(self::PACKET_TYPE_COMMAND, $command);
        if (fwrite($this->socket, $packet) === false) {
            throw new Exception("发送命令失败");
        }
        
        $header = fread($this->socket, 12);
        if ($header === false || strlen($header) < 12) {
            throw new Exception("读取响应头失败");
        }
        
        $headerInfo = $this->parsePacketHeader($header);
        $size = $headerInfo['size'];
        
        $response = "";
        $remaining = $size - 8;
        if ($remaining > 0) {
            $chunkSize = 4096;
            while ($remaining > 0) {
                $readSize = min($chunkSize, $remaining);
                $chunk = fread($this->socket, $readSize);
                if ($chunk === false) {
                    throw new Exception("读取响应体失败");
                }
                $response .= $chunk;
                $remaining -= strlen($chunk);
            }
        }
        
        return rtrim($response, "\0");
    }
    
    private function parsePacketHeader($header) {
        return [
            'size' => unpack("V", substr($header, 0, 4))[1],
            'id' => unpack("V", substr($header, 4, 4))[1],
            'type' => unpack("V", substr($header, 8, 4))[1]
        ];
    }
    
    private function createPacket($type, $payload) {
        $id = mt_rand(1, 999999); // 使用随机ID
        $packet = pack("VV", $id, $type) . $payload . "\0\0";
        $size = strlen($packet);
        return pack("V", $size) . $packet;
    }
    
    public function disconnect() {
        if ($this->connected && $this->socket) {
            fclose($this->socket);
            $this->connected = false;
        }
    }
    
    public function isConnected() {
        return $this->connected;
    }
    
    public function __destruct() {
        $this->disconnect();
    }
}

class RCONConsole {
    private $configFile = "rcon_config.json";
    private $servers = [];
    private $currentServer = null;
    private $clientVersion = "1.0.2";
    private $rconClient = null;
    
    private $internalCommands = [
        '#help', '#connect', '#disconnect', '#servers', '#add', 
        '#remove', '#select', '#exit', '#quit', '#clear', '#status'
    ];
    
    // 颜色映射表(不是很准)
    private $colorMap = [
        '§0' => "\033[0;30m", '§1' => "\033[0;34m", '§2' => "\033[0;32m",
        '§3' => "\033[0;36m", '§4' => "\033[0;31m", '§5' => "\033[0;35m",
        '§6' => "\033[0;33m", '§7' => "\033[0;37m", '§8' => "\033[1;30m",
        '§9' => "\033[1;34m", '§a' => "\033[1;32m", '§b' => "\033[1;36m",
        '§c' => "\033[1;31m", '§d' => "\033[1;35m", '§e' => "\033[1;33m",
        '§f' => "\033[1;37m", '§l' => "\033[1m",    '§r' => "\033[0m",
    ];
    
    public function __construct() {
        $this->loadConfig();
        $this->showHeader();
    }
    
    private function loadConfig() {
        if (file_exists($this->configFile)) {
            $config = json_decode(file_get_contents($this->configFile), true);
            if ($config) {
                $this->servers = $config['servers'] ?? [];
                $this->currentServer = $config['current_server'] ?? null;
            }
        }
        
        if (empty($this->servers)) {
            $this->initializeDefaultConfig();
        }
    }
    
    private function initializeDefaultConfig() {
        $this->servers = [
            'default' => [
                'host' => '127.0.0.1',
                'port' => 19132,
                'password' => '',
                'name' => '默认服务器'
            ]
        ];
        $this->currentServer = 'default';
        $this->saveConfig();
    }
    
    private function saveConfig() {
        $config = [
            'servers' => $this->servers,
            'current_server' => $this->currentServer,
            'config_version' => '1.0'
        ];
        $result = file_put_contents(
            $this->configFile, 
            json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
        
        if ($result === false) {
            throw new Exception("保存配置文件失败");
        }
    }
    
    private function showHeader() {
        $header = [
            "\033[1;36m" . str_repeat("=", 60) . "\033[0m",
            "\033[1;32m              PHPRCON 连接工具\033[0m",
            "\033[1;33m        Windows1145编写    版本:{$this->clientVersion}\033[0m",
            "\033[1;36m" . str_repeat("=", 60) . "\033[0m",
            "当前服务器: \033[1;35m" . ($this->currentServer ? $this->servers[$this->currentServer]['name'] : '无') . "\033[0m",
            "输入 '#help' 查看可用命令",
            "\033[1;36m" . str_repeat("-", 60) . "\033[0m"
        ];
        
        echo implode("\n", $header) . "\n";
    }
    
    public function run() {
        while (true) {
            $prompt = $this->currentServer ? 
                "\033[1;34mRCON[{$this->servers[$this->currentServer]['name']}]> \033[0m" : 
                "\033[1;34mRCON> \033[0m";
            
            echo $prompt;
            $input = trim(fgets(STDIN));
            
            if (empty($input)) {
                continue;
            }
            
            $args = preg_split('/\s+/', $input, 2);
            $command = strtolower($args[0]);
            $commandArgs = isset($args[1]) ? preg_split('/\s+/', $args[1]) : [];
            
            try {
                if (in_array($command, $this->internalCommands)) {
                    $this->handleInternalCommand($command, $commandArgs);
                } else {
                    $this->sendRCONCommand($input);
                }
            } catch (Exception $e) {
                echo "\033[1;31m错误: " . $e->getMessage() . "\033[0m\n";
            }
        }
    }
    
    private function handleInternalCommand($command, $args) {
        $handlers = [
            '#help' => 'showHelp',
            '#connect' => 'connectServer',
            '#disconnect' => 'disconnectServer',
            '#servers' => 'listServers',
            '#add' => 'addServer',
            '#remove' => 'removeServer',
            '#select' => 'selectServer',
            '#clear' => 'clearScreen',
            '#status' => 'checkServerStatus',
            '#exit' => 'exitConsole',
            '#quit' => 'exitConsole'
        ];
        
        if (isset($handlers[$command])) {
            $this->{$handlers[$command]}($args);
        }
    }
    
    private function showHelp() {
        $help = [
            "\033[1;33m可用命令:\033[0m",
            "  \033[1;32m#help\033[0m        - 显示此帮助信息",
            "  \033[1;32m#connect\033[0m     - 连接到当前选中的服务器",
            "  \033[1;32m#disconnect\033[0m  - 断开当前连接",
            "  \033[1;32m#servers\033[0m     - 显示服务器列表",
            "  \033[1;32m#add\033[0m         - 添加新服务器 (add <名称> <IP> <端口> <密码>)",
            "  \033[1;32m#remove\033[0m      - 移除服务器 (remove <名称>)",
            "  \033[1;32m#select\033[0m      - 选择服务器 (select <名称>)",
            "  \033[1;32m#status\033[0m      - 检查服务器状态",
            "  \033[1;32m#clear\033[0m       - 清空屏幕",
            "  \033[1;32m#exit/quit\033[0m   - 退出程序",
            "  \033[1;32m<其他命令>\033[0m    - 发送RCON命令到服务器",
            "",
            "\033[1;33m示例:\033[0m",
            "  #add 主服务器 127.0.0.1 19132 mypassword",
            "  #select 主服务器",
            "  #connect",
            "  list"
        ];
        
        echo implode("\n", $help) . "\n";
    }
    
    private function connectServer($args) {
        if (!$this->currentServer) {
            throw new Exception("请先使用 '#select' 命令选择一个服务器");
        }
        
        $server = $this->servers[$this->currentServer];
        echo "正在连接到 {$server['name']} ({$server['host']}:{$server['port']})...\n";
        
        $this->rconClient = new RCONClient($server['host'], $server['port'], $server['password']);
        $this->rconClient->connect();
        
        echo "\033[1;32m连接成功!\033[0m\n";
        
        // 测试连接
        $this->checkServerStatus();
    }
    
    private function sendRCONCommand($command) {
        if (!$this->currentServer) {
            throw new Exception("请先使用 '#select' 和 '#connect' 命令连接服务器");
        }
        
        if (!$this->rconClient || !$this->rconClient->isConnected()) {
            $server = $this->servers[$this->currentServer];
            $this->rconClient = new RCONClient($server['host'], $server['port'], $server['password']);
            $this->rconClient->connect();
        }
        
        $response = $this->rconClient->sendCommand($command);
        $cleanResponse = $this->parseColors($response);
        echo "\033[1;36m服务器响应:\033[0m " . $cleanResponse . "\n";
    }
    
    private function checkServerStatus() {
        if (!$this->rconClient || !$this->rconClient->isConnected()) {
            echo "服务器状态: \033[1;31m未连接\033[0m\n";
            return;
        }
        
        try {
            $response = $this->rconClient->sendCommand("list");
            echo "服务器状态: \033[1;32m在线\033[0m\n";
            echo "玩家列表: " . $this->parseColors($response) . "\n";
        } catch (Exception $e) {
            echo "服务器状态: \033[1;33m连接正常但命令执行失败: " . $e->getMessage() . "\033[0m\n";
        }
    }

    private function parseColors($text) {
        return str_replace(array_keys($this->colorMap), array_values($this->colorMap), $text) . "\033[0m";
    }

    private function listServers() {
        if (empty($this->servers)) {
            echo "没有配置任何服务器\n";
            return;
        }
        
        echo "\033[1;33m服务器列表:\033[0m\n";
        foreach ($this->servers as $id => $server) {
            $current = ($id === $this->currentServer) ? " \033[1;32m[当前]\033[0m" : "";
            echo "  {$server['name']} - {$server['host']}:{$server['port']}{$current}\n";
        }
    }
    
    private function addServer($args) {
        if (count($args) < 4) {
            throw new Exception("用法: #add <名称> <IP> <端口> <密码>");
        }
        
        list($name, $host, $port, $password) = $args;
        
        if (!filter_var($host, FILTER_VALIDATE_IP) && !filter_var($host, FILTER_VALIDATE_DOMAIN)) {
            throw new Exception("无效的IP地址或域名");
        }
        
        if ($port < 1 || $port > 65535) {
            throw new Exception("端口号必须在1-65535之间");
        }
        
        $id = strtolower($name);
        $this->servers[$id] = [
            'name' => $name,
            'host' => $host,
            'port' => (int)$port,
            'password' => $password
        ];
        
        $this->saveConfig();
        echo "\033[1;32m服务器 '{$name}' 添加成功!\033[0m\n";
    }
    
    private function removeServer($args) {
        if (empty($args)) {
            throw new Exception("用法: #remove <服务器名称>");
        }
        
        $name = strtolower($args[0]);
        if (!isset($this->servers[$name])) {
            throw new Exception("服务器 '{$args[0]}' 不存在");
        }
        
        unset($this->servers[$name]);
        if ($this->currentServer === $name) {
            $this->currentServer = null;
            if ($this->rconClient) {
                $this->rconClient->disconnect();
                $this->rconClient = null;
            }
        }
        
        $this->saveConfig();
        echo "\033[1;32m服务器 '{$args[0]}' 移除成功!\033[0m\n";
    }
    
    private function selectServer($args) {
        if (empty($args)) {
            throw new Exception("用法: #select <服务器名称>");
        }
        
        $name = strtolower($args[0]);
        if (!isset($this->servers[$name])) {
            throw new Exception("服务器 '{$args[0]}' 不存在");
        }
        
        $this->currentServer = $name;
        $this->saveConfig();
        echo "\033[1;32m已选择服务器: {$this->servers[$name]['name']}\033[0m\n";
    }
    
    private function disconnectServer() {
        if ($this->rconClient) {
            $this->rconClient->disconnect();
            $this->rconClient = null;
        }
        echo "\033[1;32m已断开服务器连接\033[0m\n";
    }
    
    private function clearScreen() {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            system('cls');
        } else {
            system('clear');
        }
        $this->showHeader();
    }
    
    private function exitConsole() {
        if ($this->rconClient) {
            $this->rconClient->disconnect();
        }
        echo "已退出PHPRCON客户端!\n";
        exit(0);
    }
    
    public function __destruct() {
        if ($this->rconClient) {
            $this->rconClient->disconnect();
        }
    }
}

// 主程序入口
try {
    if (PHP_SAPI !== 'cli') {
        die("此程序必须在命令行模式下运行\n");
    }
    
    $console = new RCONConsole();
    $console->run();
    
} catch (Exception $e) {
    echo "\033[1;31m致命错误: " . $e->getMessage() . "\033[0m\n";
    exit(1);

}
