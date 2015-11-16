<?php

// Required functions for displaying wiki markup
require "../../functions.php";
require "../../src/markup/fishformat.php";

// Recaptcha library for logging in
require "../../recaptchalib.php";

class API
{
    // The model must be passed when the API is constructed
    function __construct($model)
    {
        $this->model = $model;
        session_start();

        if(!isset($_SESSION['status']))
        {
            $_SESSION['status'] =
            [
                'authed' => false,
                'credits' => 0
            ];
        }
    }

    // Private function to set the session data for a user
    private function setStatus($status, $credits)
    {
        $status = (bool)$status;
        $credits = (int)$credits;
        
        if($status)
        {
            // Set legacy session values (required for editing posts)
            $_SESSION['bypass'] = true;
            $_SESSION['api'] = true;
        }
        
        $_SESSION['status']['authed'] = $status;
        $_SESSION['status']['credits'] = $credits;
    }

    // Private function to check if requests are authenticated
    private function isAuthenticated()
    {
        if($_SESSION['status']['authed'])
        {
            return true;
        }

        header('Content-Type: application/json');
        echo json_encode(array('status' => 'error', 'message' => 'You must be authenticated to perform this action.'));
        exit;
    }

    // Function to process requests
    public function request($url)
    {
        $url = parse_url($url);

        // Remove leading and trailing slashes, then explode the path into an array
        $path = explode('/', trim($url['path'], '/'));

        // Ignore the first two segments (/api/v1)
        $path = array_slice($path, 2);
        $method = array_shift($path);

        if(method_exists($this, $method))
        {
            return call_user_func(array($this, $method), $path);
        }
        else
        {
            $response = array
            (
                'status' => 'error',
                'code' => 400,
                'message' => 'Invalid method.'
            );

            header('Content-Type: application/json');
            return json_encode($response);
        }
    }

    // Function to display a page's content
    public function content($path)
    {
        $path = implode('/', $path);
        $result = $this->model->page->get(array('path' => $path));
        $page = $result->fetch_object();

        return FishFormat($page->Content);
    }

    // Function to display a page's source
    public function source($path)
    {
        $path = implode('/', $path);
        $result = $this->model->page->get(array('path' => $path));
        $page = $result->fetch_object();

        return $page->Content;
    }

    // Function to display JSON containing a page's content
    public function json($path)
    {
        $path = implode('/', $path);
        $page_result = $this->model->page->get(array('path' => $path));
        $page = $page_result->fetch_object();

        $tag_result = $this->model->tags->get(array('pageID' => $page->ID));
        $tags = array();
        
        while($tag = $tag_result->fetch_object())
        {
            $tags[] = str_replace('-', ' ', $tag->tag);
        }

        if(empty($page))
        {
            $response = array
            (
                'status' => 'error',
                'code' => 404,
                'message' => 'Page does not exist.'
            );

            header('Content-Type: application/json');
            return json_encode($response);
        }
        else
        {
            $response = array
            (
                'status' => 'success',
                'code' => 200,
                'path' => $page->Path,
                'views' => $page->Views,
                'edits' => count(explode(',', $page->Edits)),
                'modified' => $page->EditTime,

                'title' => array
                (
                    'formatted' => FishFormat($page->Title),
                    'source' => $page->Title
                ),

                'content' => array
                (
                    'formatted' => FishFormat($page->Content),
                    'source' => $page->Content
                ),

                'tags' => $tags
            );

            header('Content-Type: application/json');
            return json_encode($response);
        }
    }

    public function status()
    {
        header('Content-Type: application/json');
        return json_encode($_SESSION['status']);
    }

    public function auth()
    {
        // Check if post data was submitted
        if(!empty($_POST))
        {
            if(defined('CAPTCHA_BYPASS') && CAPTCHA_BYPASS)
            {
                if(preg_match(CAPTCHA_BYPASS, $_POST["recaptcha_response_field"]))
                {
                    $this->setStatus(true, 10);
                }
            }
            
            if(!isset($_SESSION['bypass']))
            {
                $Resp = recaptcha_check_answer(RECAPTCHA_PRIVATE,
                                                $_SERVER["REMOTE_ADDR"],
                                                $_POST["recaptcha_challenge_field"],
                                                $_POST["recaptcha_response_field"]);

                if($Resp->is_valid)
                {
                    $this->setStatus(true, 10);
                }
            }
        }
        
        // Otherwise return a captcha
        $script = "<script>var RecaptchaOptions = {theme : 'blackglass'}</script>";
        $form = "<form method='post'>" . recaptcha_get_html(RECAPTCHA_PUBLIC, null, 1) . "</form>";
        return $script . $form;
    }

    // Function to list every page on the wiki (requires auth)
    public function pages()
    {
        if($this->isAuthenticated())
        {
            unset($_SESSION['bypass']);
            unset($_SESSION['api']);

            $result = $this->model->page->get(['1' => 1], 'Path, Title');
            $pages = [];

            while($page = $result->fetch_object())
            {
                array_push($pages, array
                (
                    'path' => '/' . $page->Path,
                    'title' => $page->Title
                ));
            }

            header('Content-Type: application/json');
            return json_encode($pages);
        }
    }

    // Function to list every tag on the wiki (requires auth)
    public function tags()
    {
        if($this->isAuthenticated())
        {
            unset($_SESSION['bypass']);
            unset($_SESSION['api']);

            $result = $this->model->tags->stats(['1' => 1], 'tag, count, views');
            $tags = [];

            while($tag = $result->fetch_object())
            {
                array_push($tags, (array)$tag);
            }

            header('Content-Type: application/json');
            return json_encode($tags);
        }
    }
}

?>
