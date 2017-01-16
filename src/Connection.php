<?php
namespace SamIT\React\Smtp;


use React\Dns\Query\TimeoutException;
use React\EventLoop\LoopInterface;
use React\EventLoop\Timer\TimerInterface;
use React\Socket\ConnectionInterface;
use React\Stream\WritableStream;

class Connection extends \React\Socket\Connection{
    const STATUS_NEW = 0;
    const STATUS_INIT = 1;
    const STATUS_FROM = 2;
    const STATUS_TO = 3;
    const STATUS_HEADERS = 4;
    const STATUS_UNFOLDING = 5;
    const STATUS_BODY = 6;


    /**
     * This status is used when all mail data has been received and the system is deciding whether to accept or reject.
     */
    const STATUS_PROCESSING = 7;


    const REGEXES = [
        'Quit' => '/^QUIT$/',
        'Helo' => '/^HELO (.*)$/',
        'Ehlo' => '/^EHLO (.*)$/',
        'MailFrom' => '/^MAIL FROM:\s*(.*)$/',
        'Reset' => '/^RSET$/',
        'RcptTo' => '/^RCPT TO:\s*(.*)$/',
        'StartData' => '/^DATA$/',
        'StartHeader' => '/^(\w+):\s*(.*)$/',
        'StartBody' => '/^$/',
        'Unfold' => '/^ (.*)$/',
        'EndData' => '/^\.$/',
        'BodyLine' => '/^(.*)$/',
        'EndBody' => '/^\.$/'
    ];

    protected $states = [
        self::STATUS_NEW => [
            'Quit', 'Helo', 'Ehlo'
        ],
        self::STATUS_INIT => [
            'MailFrom',
            'Quit'

        ],
        self::STATUS_FROM => [
            'RcptTo',
            'Quit',
            'Reset',
        ],
        self::STATUS_TO => [
            'Quit',
            'StartData',
            'Reset',
            'RcptTo',

        ],
        self::STATUS_HEADERS => [
            'EndBody',
            'StartHeader',
            'StartBody',
        ],
        self::STATUS_UNFOLDING => [
            'StartBody',
            'EndBody',
            'Unfold',
            'StartHeader',
        ],
        self::STATUS_BODY => [
            'EndBody',
            'BodyLine'
        ],
        self::STATUS_PROCESSING => [

        ]



    ];

    protected $state = self::STATUS_NEW;

    protected $banner = 'Welcome to ReactPHP SMTP Server';
    /**
     * @var bool Accept messages by default
     */
    protected $acceptByDefault = true;
    /**
     * If there are event listeners, how long will they get to accept or reject a message?
     * @var int
     */
    protected $defaultActionTimeout = 5;
    /**
     * The timer for the default action, canceled in [accept] and [reject]
     * @var TimerInterface
     */
    protected $defaultActionTimer;
    /**
     * The current line buffer used by handleData.
     * @var string
     */
    protected $lineBuffer = '';

    /**
     * @var string Name of the header in the foldBuffer.
     */
    protected $foldHeader = '';
    /**
     * Buffer used for unfolding multiline headers..
     * @var string
     */
    protected $foldBuffer = '';
    protected $from;
    protected $recipients = [];
    /**
     * @var Message
     */
    protected $message;

    public $bannerDelay = 0;


    public $recipientLimit = 100;

    public function __construct($stream, LoopInterface $loop)
    {
        parent::__construct($stream, $loop);
        stream_get_meta_data($stream);
        // We sleep for 3 seconds, if client does not wait for our banner we disconnect.
        $disconnect = function($data, ConnectionInterface $conn) {
            $conn->end("I can break rules too, bye.\n");
        };
        $this->on('data', $disconnect);
        $this->reset(self::STATUS_NEW);
        $this->on('line', [$this, 'handleCommand']);
        if ($this->bannerDelay > 0) {
            $loop->addTimer($this->bannerDelay, function () use ($disconnect) {
                $this->sendReply(220, $this->banner);
                $this->removeListener('data', $disconnect);
            });
        } else {
            $this->sendReply(220, $this->banner);
        }
    }

    /**
     * We read until we find an and of line sequence for SMTP.
     * http://www.jebriggs.com/blog/2010/07/smtp-maximum-line-lengths/
     * @param $stream
     */
    public function handleData($stream)
    {
        // Socket is raw, not using fread as it's interceptable by filters
        // See issues #192, #209, and #240
        $data = stream_socket_recvfrom($stream, $this->bufferSize);;

        $limit = $this->state == self::STATUS_BODY ? 1000 : 512;
        if ('' !== $data && false !== $data) {
            $this->lineBuffer .= $data;
            if (strlen($this->lineBuffer) > $limit) {
                $this->sendReply(500, "Line length limit exceeded.");
                $this->lineBuffer = '';
            }

            $delimiter = "\r\n";
            while(false !== $pos = strpos($this->lineBuffer, $delimiter)) {
                $line = substr($this->lineBuffer, 0, $pos);
                $this->lineBuffer = substr($this->lineBuffer, $pos + strlen($delimiter));
                $this->emit('line', [$line, $this]);
            }
        }

        if ('' === $data || false === $data || !is_resource($stream) || feof($stream)) {
            $this->end();
        }
    }

    /**
     * Parses the command from the beginning of the line.
     *
     * @param string $line
     * @return string[] An array containing the command and all arguments.
     */
    protected function parseCommand($line)
    {

        foreach ($this->states[$this->state] as $key) {
            if (preg_match(self::REGEXES[$key], $line, $matches) === 1) {
                $matches[0] = $key;
                $this->emit('debug', "$line match for $key (" . self::REGEXES[$key] . ")");
                return $matches;
            } else {
                $this->emit('debug', "$line does not match for $key (" . self::REGEXES[$key] . ")");
            }
        }
        return [null];
    }

    protected function handleCommand($line)
    {
        $arguments = $this->parseCommand($line);
        $command = array_shift($arguments);
        if ($command == null) {
            $this->sendReply(500, array_merge(
                $this->states[$this->state],
                ["Unexpected or unknown command."]
            ));
        } else {
            call_user_func_array([$this, "handle{$command}Command"], $arguments);
        }
    }

    protected function sendReply($code, $message, $close = false)
    {
        $out = '';
        if (is_array($message)) {
            $last = array_pop($message);
            foreach($message as $line) {
                $out .= "$code-$line\r\n";
            }
            $this->write($out);
            $message = $last;
        }
        if ($close) {
            $this->end("$code $message\r\n");
        } else {
            $this->write("$code $message\r\n");
        }

    }

    protected function handleResetCommand($domain)
    {
        $this->reset();
        $this->sendReply(250, "Reset OK");
    }
    protected function handleHeloCommand($domain)
    {
        $this->state = self::STATUS_INIT;
        $this->sendReply(250, "Hello {$this->getRemoteAddress()}");
    }

    protected function handleEhloCommand($domain)
    {
        $this->state = self::STATUS_INIT;
        $this->sendReply(250, "Hello {$this->getRemoteAddress()}");
    }

    protected function handleMailFromCommand($arguments)
    {

        // Parse the email.
        if (preg_match('/\<(?<email>.*)\>( .*)?/', $arguments, $matches) == 1) {
            $this->state = self::STATUS_FROM;
            $this->from  = $matches['email'];
            $this->sendReply(250, "MAIL OK");
        } else {
            $this->sendReply(500, "Invalid mail argument");
        }

    }

    protected function handleQuitCommand($arguments)
    {
        $this->sendReply(221, "Goodbye.", true);

    }

    protected function handleRcptToCommand($arguments) {
        // Parse the recipient.
        if (preg_match('/^(?<name>.*?)\s*?\<(?<email>.*)\>\s*$/', $arguments, $matches) == 1) {
            // Always set to 3, since this command might occur multiple times.
            $this->state = self::STATUS_TO;
            $this->recipients[$matches['email']] = $matches['name'];
            $this->sendReply(250, "Accepted");
        } else {
            $this->sendReply(500, "Invalid RCPT TO argument.");
        }
    }

    protected function handleStartDataCommand()
    {
        $this->state = self::STATUS_HEADERS;
        $this->sendReply(354, "Enter message, end with CRLF . CRLF");
    }

    protected function handleUnfoldCommand($content)
    {
        $this->foldBuffer .= $content;
    }

    protected function handleStartHeaderCommand($name, $content)
    {
        // Check if status is unfolding.
        if ($this->state === self::STATUS_UNFOLDING) {
            $this->message = $this->message->withAddedHeader($this->foldHeader, $this->foldBuffer);
        }

        $this->foldBuffer = $content;
        $this->foldHeader = $name;
        $this->state = self::STATUS_UNFOLDING;
    }

    protected function handleStartBodyCommand()
    {
        // Check if status is unfolding.
        if ($this->state === self::STATUS_UNFOLDING) {
            $this->message = $this->message->withAddedHeader($this->foldHeader, $this->foldBuffer);
        }
        $this->state = self::STATUS_BODY;

    }

    protected function handleEndBodyCommand()
    {
        // Check if status is unfolding.
        if ($this->state === self::STATUS_UNFOLDING) {
            $this->message = $this->message->withAddedHeader($this->foldHeader, $this->foldBuffer);
        }

        $this->state = self::STATUS_PROCESSING;
        /**
         * Default action, using timer so that callbacks above can be called asynchronously.
         */
        $this->defaultActionTimer = $this->loop->addTimer($this->defaultActionTimeout, function() {
            if ($this->acceptByDefault) {
                $this->accept();
            } else {
                $this->reject();
            }
        });



        $this->emit('message', [
            'from' => $this->from,
            'recipients' => $this->recipients,
            'message' => $this->message,
            'connection' => $this,
        ]);
    }
    protected function handleBodyLineCommand($line)
    {
        $this->message->getBody()->write($line);
    }

    /**
     * Reset the SMTP session.
     * By default goes to the initialized state (ie no new EHLO or HELO is required / possible.)
     *
     * @param int $state The state to go to.
     */
    protected function reset($state = self::STATUS_INIT) {
        $this->state = $state;
        $this->from = null;
        $this->recipients = [];
        $this->message = new Message();
    }

    public function accept($message = "OK") {
        if ($this->state != self::STATUS_PROCESSING) {
            throw new \DomainException("SMTP Connection not in a valid state to accept a message.");
        }
        $this->loop->cancelTimer($this->defaultActionTimer);
        unset($this->defaultActionTimer);
        $this->sendReply(250, $message);
        $this->reset();
    }

    public function reject($code = 550, $message = "Message not accepted") {
        if ($this->state != self::STATUS_PROCESSING) {
            throw new \DomainException("SMTP Connection not in a valid state to reject message.");
        }
        $this->defaultActionTimer->cancel();
        unset($this->defaultActionTimer);
        $this->sendReply($code, $message);
        $this->reset();
    }

    /**
     * Delay the default action by $seconds.
     * @param int $seconds
     */
    public function delay($seconds) {
        if (isset($this->defaultActionTimer)) {
            $this->defaultActionTimer->cancel();
            $this->defaultActionTimer = $this->loop->addTimer($seconds, $this->defaultActionTimer->getCallback());
        }
    }

}
