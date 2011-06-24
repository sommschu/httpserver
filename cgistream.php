<?php

/*
 * CGIStream is a PHP stream wrapper (http://www.php.net/manual/en/class.streamwrapper.php)
 * that wraps the stdout pipe from a CGI process. It buffers the output until the CGI process is 
 * complete, and then rewrites some HTTP headers (Content-Type, Status, Server) and sets the HTTP status code
 * before returning the output stream from fread().
 *
 * This allows the server to be notified via stream_select() when the CGI output is ready, rather than waiting
 * until the CGI process completes.
 */
class CGIStream 
{
    public $context;
      
    private $buffer = '';
    private $buffer_stream;    
    
    private $cur_state = 0;
    
    const BUFFERING = 0;
    const BUFFERED = 1;
    const EOF = 2;
    
    private $proc;
    private $stream;
    private $in_stream;
    private $server;
    
    /*
     * Used by stream_select to determine when there is data ready on this stream.
     * We read the CGI response into a stream of type data:// so that stream_select
     * knows when we have more data for it.
     */
    function stream_cast($cast_as)
    {
        return ($this->cur_state == static::BUFFERING) ? $this->stream : $this->buffer_stream;
    }
    
    function stream_open($path, $mode, $options, &$opened_path)
    {
        $options = stream_context_get_options($this->context);
        
        $cgi_opts = $options['cgi'];
        
        $this->proc = $cgi_opts['proc'];
        $this->stream = $cgi_opts['stream'];
        $this->in_stream = $cgi_opts['in_stream'];
        $this->server = $cgi_opts['server'];

        return true;
    }

    function stream_read($count)
    {
        switch ($this->cur_state)
        {
            case static::BUFFERING:
                $buffer =& $this->buffer;
                
                // sadly this blocks on Windows.
                // non-blocking pipes don't work in PHP on Windows, and stream_select doesn't know when the pipe has data
                $data = fread($this->stream, $count);
                
                if ($data !== false)
                {        
                    $buffer .= $data;
                    
                    // need to wait until CGI is finished to determine Content-Length
                    if (!feof($this->stream)) 
                    {
                        return '';
                    }
                }

                // now the CGI process has finished sending data.
                // CGI process sends HTTP status as regular header,
                // which we need to convert to HTTP status line.
                // also, need to add Content-Length header for HTTP keep-alive
                
                $end_response_headers = strpos($buffer, "\r\n\r\n");                
                if ($end_response_headers === false)
                {
                    $response = $this->server->text_response(502, "Invalid Response from CGI process");
                }
                else
                {                
                    $headers_str = substr($buffer, 0, $end_response_headers);        
                    $headers = HTTPServer::parse_headers($headers_str);        
                    
                    if (isset($headers['Status']))
                    {
                        $status = (int) $headers['Status'];
                        unset($headers['Status']);
                    }                
                    else
                    {
                        $status = 200;
                    }                
                    
                    $content = substr($buffer, $end_response_headers + 4);                            
                    $response = $this->server->response($status, $content, $headers);
                }
                    
                $this->cur_state = static::BUFFERED;                
                
                $this->buffer_stream = fopen('data://text/plain,', 'r+b');
                
                fwrite($this->buffer_stream, $response->render());
                fseek($this->buffer_stream, 0);
                
                // intentional fallthrough 
            case static::BUFFERED:
                $res = fread($this->buffer_stream, $count);

                if (feof($this->buffer_stream))
                {
                    $this->cur_state = static::EOF;
                }                
                return $res;
            case static::EOF;
                return false;
        }                            
    }
    
    function stream_eof()
    {
        return $this->cur_state == static::EOF;
    }

    function stream_close()
    {
        proc_close($this->proc);        
        $this->proc = null;
        
        fclose($this->stream); 
        $this->stream = null;

        fclose($this->in_stream);
        $this->in_stream = null;
        
        if ($this->buffer_stream)
        {
            fclose($this->buffer_stream);
            $this->buffer_stream = null;
        }
        $this->buffer = null;
        $this->server = null;        
    }
}
