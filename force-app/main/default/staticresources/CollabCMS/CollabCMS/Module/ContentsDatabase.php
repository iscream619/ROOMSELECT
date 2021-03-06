<?php

require_once dirname(__FILE__) . "/Debug.php";


if(!defined('CONTENTS_HOME_DIR') ) define('CONTENTS_HOME_DIR', getcwd());

class ContentsDatabase {
    const MAX_LATEST_COUNT = 20;

    /**
     * [
     *  'tag2path' => 
     *      [
     *          'tag' => ['path' => true, ...],
     *          ...
     *      ],
     *  'path2tag' =>
     *      [
     *          'path' => ['tag' => true, ...],
     *          ...
     *      ],
     *  'latest' => ['path' => timestamp, ...],
     *  'contentsChangedTime' => timestamp,
     *  'createdTime' => timestamp,
     *  'openedTime' => timestamp,
     *  'closedTime' => timestamp
     * ]
     */
    public static $metadata = [];
    public static $metadataOpenedTime = null;
    
    public static function RegistTag($path, $tag){
        self::$metadata['tag2path'][$tag][$path] = true;
        self::$metadata['path2tag'][$path][$tag] = true;
    }

    public static function UnregistTag($path){
        if(!array_key_exists('path2tag', self::$metadata) ||
            !array_key_exists($path, self::$metadata['path2tag'])){
            return;
        }

        if(!array_key_exists('tag2path', self::$metadata)){
            return;
        }

        foreach(self::$metadata['path2tag'][$path] as $tag => $value){
            if(!array_key_exists($tag, self::$metadata['tag2path']) ||
                !array_key_exists($path, self::$metadata['tag2path'][$tag])){
                continue;
            }

            unset(self::$metadata['tag2path'][$tag][$path]);
            if(empty(self::$metadata['tag2path'][$tag])){
                unset(self::$metadata['tag2path'][$tag]);
            }
        }
    
        unset(self::$metadata['path2tag'][$path]);
    }

    public static function NotifyContentsChange($timestamp){
        if(!array_key_exists('contentsChangedTime', self::$metadata)){
            self::$metadata['contentsChangedTime'] = $timestamp;
            return;
        }

        if(self::$metadata['contentsChangedTime'] < $timestamp){
            self::$metadata['contentsChangedTime'] = $timestamp;
        }
    }

    public static function RegistLatest($path, $timestamp){
        if(!array_key_exists('latest', self::$metadata)){
            self::$metadata['latest'] = [];
        }

        self::UnregistLatest($path);

        $latestCount = count(self::$metadata['latest']);
        if($latestCount < self::MAX_LATEST_COUNT){
            self::$metadata['latest'][$path] = $timestamp;
            return;
        }

        $minTimestamp = reset(self::$metadata['latest']);
        $oldest = key(self::$metadata['latest']);
        foreach(self::$metadata['latest'] as $pt => $ts){
            if($ts < $minTimestamp){
                $oldest = $pt;
                $minTimestamp = $ts;
            }
        }

        if($timestamp < self::$metadata['latest'][$oldest]){
            return;
        }

        unset(self::$metadata['latest'][$oldest]);
        self::$metadata['latest'][$path] = $timestamp;
    }

    public static function UnregistLatest($path){
        if(!array_key_exists('latest', self::$metadata) ||
            !array_key_exists($path, self::$metadata['latest'])){
            return;
        }

        unset(self::$metadata['latest'][$path]);
    }

    public static function CrawlContents($rootContentPath, $callback) {
        $content = new Content();
        
        $contentPathStack = [];
        $contentPathStack[] = $rootContentPath;
        $contentPathStackCount = 1;

        $openContentPathMap = [];
        
        while($contentPathStackCount > 0){
            //var_dump($contentPathStack);

            $contentPathStackCount--;
            $path = array_pop($contentPathStack);

            if( !$content->SetContent($path) ){
                continue;
            }

            if(array_key_exists($content->path, $openContentPathMap)){
                Debug::LogWarning("[UpdateContentsMetadata] >> Detect Circular reference. " . $content->path);
                continue;
            }

            $openContentPathMap[$content->path] = null;
            call_user_func_array($callback, [$content]);

            $childPathListCount = count($content->childPathList);
            for($i = 0; $i < $childPathListCount; $i++){
                $childPath = dirname($content->path) . '/' . $content->childPathList[$i];
                $contentPathStack[] = $childPath;
                $contentPathStackCount++;
            }
        }
    }

    public static function SaveMetadata($metaFileName) {
        if(!array_key_exists('createdTime', self::$metadata)){
            self::$metadata['createdTime'] = time();
        }

        if(is_null(self::$metadataOpenedTime)){
            self::$metadataOpenedTime = time();
        }
        self::$metadata['openedTime'] = self::$metadataOpenedTime;
        self::$metadata['closedTime'] = time();

        $metaFileName = Content::RealPath($metaFileName, '', false);
        $encoded = json_encode(self::$metadata);
        file_put_contents($metaFileName , $encoded, LOCK_EX);
    }

    public static function LoadMetadata($metaFileName) {
        $metaFileName = Content::RealPath($metaFileName, '', false);
        //Debug::Log($metaFileName);
        if(file_exists($metaFileName) && is_file($metaFileName)){
            self::$metadataOpenedTime = time();
            
            $fp = fopen($metaFileName, "r");
            if($fp === false || !flock($fp, LOCK_SH)){
                fclose($fp);
                return false;
            }
            $json = stream_get_contents($fp);
            fclose($fp);

            $json = mb_convert_encoding($json, 'UTF8', 'ASCII,JIS,UTF-8,EUC-JP,SJIS-WIN');
            self::$metadata = json_decode($json, true);

            return true;
        }

        return false;
    }
}

class Content {
    const EXTENTION = '.content';

    private static $elementTagMap =
    [
        "Header" => ["StartTag" => "<Header>", "EndTag" => "</Header>"],
        "Parent" => ["StartTag" => "<Parent>", "EndTag" => "</Parent>"],
        "Child" => ["StartTag" => "<Child>", "EndTag" => "</Child>"],
        "Title" => ["StartTag" => "<Title>", "EndTag" => "</Title>"],
        "CreatedAt" => ["StartTag" => "<CreatedAt>", "EndTag" => "</CreatedAt>"],
        "Summary" => ["StartTag" => "<Summary>", "EndTag" => "</Summary>"],
        "Tags" => ["StartTag" => "<Tags>", "EndTag" => "</Tags>"]
    ];

    /** 
     * ???????????????????????????????????????
     * @var string
     */
    public $path = "";

    /** 
     * ??????????????????????????? 
     * @var string
     */
    public $title = "";

    /** 
     * ?????????????????????
     * @var string 
     */
    public $summary = "";

    /** 
     * ????????????????????????
     * @var string 
     */
    public $body = "";

    /**
     * ?????????????????????????????????(Unix ?????????????????????)
     * @var int
     */
    public $modifiedTime;

    /**
     * ???????????????????????????(Unix ?????????????????????)
     * @var int|false
     */
    public $createdTime;

    /**
     * ???????????????????????????(?????????????????????????????????????????????????????????)
     * @var string
     */
    public $createdTimeRaw = "";

    private $openedTime;

    /** 
     * ???????????????????????????
     * @var string
     */
    public $parentPath = "";

    //???child??????filePathList

    /** 
     * ????????????????????????????????????
     * @var array
     */
    public $childPathList = array();

    /**
     * ??????????????? [tagA, tagB, ...]
     * @var array
     */
    public $tags = array();

    /**
     * ??????Content????????????Contents??????
     */
    public function ChildCount() {return count($this->childPathList);}

    /**
     * ??????Content????????????????????????????????????
     */
    public function IsEndpoint(){return count($this->childPathList) == 0;}

    /**
     * ??????Content???????????????????????????????????????
     */
    public function IsRoot(){return $this->parentPath == "";}

    public function OpenedTime(){return $this->openedTime;}


    /**
     * ??????Content????????????????????????????????????
     */
    public function ChildIndex()
    {
        $parent = $this->Parent();
        if($parent === false)
        {
            return -1;
        }

        $myIndex = -1;
        $brothers = $parent->childPathList;
        $count = count($brothers);

        for($i = 0; $i < $count; $i++)
        {
            if(static::NormalizedPath(dirname($parent->path) . '/' . $brothers[$i]) === $this->path)
            {
                $myIndex =$i;
                break;
            }
        }

        return $myIndex;
    }


    /**
     * ??????Content?????????Child?????????
     * 
     * @param int $index ??????????????????????????????????????????????????????
     * @return Content|false ??????????????????????????????, ?????????????????????false
     */
    public function Child($index)
    {
        $childPath = dirname($this->path) . '/' . $this->childPathList[$index];
        
        $child = new Content();
        if($child->SetContent($childPath) === false)
        {
            return false;
        }

        return $child;
    }


    /**
     * ??????Content??????????????????????????????.
     * ?????????????????????, false?????????.
     * 
     * [NOTE]
     *  ?????????????????????????????????, ????????????????????????????????????????????????????????????.
     * 
     * @return Content|false ???????????????content, ?????????????????????, false
     */
    public function Parent()
    {
        if($this->parentPath === ""){
            return false;
        }

        $parentPath = dirname($this->path) . "/" . $this->parentPath;

        //Debug::Log($this->parentPath);
        $parent = new Content();
        if($parent->SetContent($parentPath) === false)
        {
            return false;
        }
        return $parent;
    }


    /**
     * file???????????????Content???????????????????????????.
     * ?????????????????????????????????, true. ????????????, false??????????????????
     * 
     * @param string $contentPath ???????????????????????????????????????. CONTENTS_HOME_DIR?????????????????????
     * @return true|false 
     */
    function SetContent($contentPath)
    {
        // Home????????????????????????????????????????????????
        $filePath = static::RealPath($contentPath);
        if($filePath === false){
            return false;
        }

        $this->openedTime = time();
        // ???????????????????????????????????????
        $this->modifiedTime = filemtime($filePath);

        $text = $this->ReadFile($filePath);
        if($text === false){
            return false;
        }

        // ??????????????????Path?????????
        $this->path = static::NormalizedPath($contentPath);

        //Content??????????????????
        $this->body = "";
        $this->childPathList = array();
        $this->parentPath = "";
        $this->tags = array();

        $bodyStartPosition = 0;
        $pattern = '/^\s*<Header>(.*?)<\/Header>/s';
        if(preg_match($pattern, $text, $matches, PREG_OFFSET_CAPTURE)){
            // Header???

            $bodyStartPosition = $matches[0][1] + strlen($matches[0][0]);
            
            $lines = explode("\n", $matches[1][0]);
            $lineCount = count($lines);

            $isInSummary = false;

            // ?????????????????????
            for($i = 0; $i < $lineCount; $i++){
            
                if($isInSummary){
                    if(strpos($lines[$i], static::$elementTagMap['Summary']['EndTag']) !== false){
                        $isInSummary = false;
                        continue;
                    }
                }
                else{
                    $position = 0;

                    if(($position = strpos($lines[$i], static::$elementTagMap['Parent']['StartTag'])) !== false){
                        $position += strlen(static::$elementTagMap['Parent']['StartTag']);

                        $this->parentPath = substr($lines[$i], $position);
                        $this->parentPath = str_replace(" ", "", $this->parentPath);

                        continue;
                    
                    } elseif(($position = strpos($lines[$i], static::$elementTagMap['Child']['StartTag'])) !== false){
                        $position += strlen(static::$elementTagMap['Child']['StartTag']);
                        
                        $childPath = substr($lines[$i], $position);
                        $childPath = str_replace(" ", "", $childPath);

                        $this->childPathList[] = $childPath;
                        
                        continue;

                    } elseif(($position = strpos($lines[$i], static::$elementTagMap['CreatedAt']['StartTag'])) !== false){
                        $position += strlen(static::$elementTagMap['CreatedAt']['StartTag']);
                        
                        $this->createdTimeRaw = substr($lines[$i], $position);
                        $this->createdTimeRaw = str_replace(" ", "", $this->createdTimeRaw);

                        $this->createdTime = strtotime($this->createdTimeRaw);
                        continue;

                    } elseif(($position = strpos($lines[$i], static::$elementTagMap['Title']['StartTag'])) !== false){
                        $position += strlen(static::$elementTagMap['Title']['StartTag']);
                        
                        $this->title = substr($lines[$i], $position);
                        $this->title = trim($this->title);
                        continue;

                    } elseif(($position = strpos($lines[$i], static::$elementTagMap['Tags']['StartTag'])) !== false){
                        $position += strlen(static::$elementTagMap['Tags']['StartTag']);
                        
                        $tagsStr = substr($lines[$i], $position);
                        $tags = explode(",", $tagsStr);
                        $tagsCount = count($tags);
                        
                        for($j = 0; $j < $tagsCount; $j++){
                            $tags[$j] = trim($tags[$j]);
                            if($tags[$j] != ""){
                                $this->tags[] = $tags[$j];
                            }
                        }
        
                        continue;

                    } elseif(($position = strpos($lines[$i], static::$elementTagMap['Summary']['StartTag'])) !== false){
                        $isInSummary = true;
                        continue;

                    }
                }

                if($isInSummary){
                    $this->summary .= $lines[$i] . "\n";
                }
            
            } // End ?????????????????????
        } // End Header??????

        // summary?????????????????????????????????
        $this->summary = substr($this->summary, 0, -1);

        $this->body = substr($text, $bodyStartPosition);

        // echo $this->summary;
        // echo $this->title;
        // echo $this->body;
        return true;
    }


    public function ToContentFileString(){
        $output = "";

        $output .= static::$elementTagMap["Header"]["StartTag"] . "\n";

        $output .= "    " . static::$elementTagMap["Parent"]["StartTag"] . " " . $this->parentPath . "\n";
        $output .= "    " . static::$elementTagMap["Title"]["StartTag"] . " " . $this->title . "\n";
        $output .= "    " . static::$elementTagMap["CreatedAt"]["StartTag"] . " " . $this->createdTimeRaw . "\n";
        $output .= "    " . static::$elementTagMap["Tags"]["StartTag"] . " " . implode(", ", $this->tags) . "\n";
        $output .= "    " . static::$elementTagMap["Summary"]["StartTag"] . "\n";
        $output .= $this->summary . "\n";
        $output .= "    " . static::$elementTagMap["Summary"]["EndTag"] . "\n";
        foreach($this->childPathList as $childPath){
            $output .= "    " . static::$elementTagMap["Child"]["StartTag"] . " " . $childPath . "\n";
        }

        $output .= static::$elementTagMap["Header"]["EndTag"] . "\n";

        $output .= $this->body;

        return $output;
    }


    public function SaveContentFile(){
        $realPath = static::RealPath($this->path);
        
        file_put_contents($this->realPath, $this->ToContentFileString(), LOCK_EX);

        //Debug::Log($output);
    }


    /**
     * @return string|false ???????????????????????????????????????. ?????????????????????false???????????????.
     */
    static function ReadFile($filePath)
    {
        if(is_dir($filePath))
        {
            Debug::LogError("[ReadFile] Fail > Directory'{$filePath}'???????????????????????????.");
            return false;
        }

        //file????????????
        $fp = fopen($filePath, "r");
        if($fp === false){
            Debug::LogError("[ReadFile] Fail > file'{$filePath}'???????????????????????????.");
            fclose($fp);
            return false;
        }

        if(!flock($fp, LOCK_SH)){
            Debug::LogError("[ReadFile] Fail > file'{$filePath}'????????????????????????????????????.");
            fclose($fp);
            return false;
        }

        $text = stream_get_contents($fp);
        fclose($fp);

        // Unix???????????????????????????(LF)?????????.
        $text = str_replace("\r", "", $text);

        //Debug::Log("[ReadFile] file'{$filePath}'????????????????????????.");

        return $text;
    }

    
    /**
     * ??????????????????????????????????????????.
     */
    public static function NormalizedPath($contentPath, $extention = null, $removeExtention = true){
        // ????????????????????????????????????????????????, Home????????????????????????
        $realPath = static::RealPath($contentPath, $extention);
        if($realPath === false){
            return false;
        }

        $relative = static::RelativePath($realPath);
        if($relative === false){
            return false;
        }

        
        // ??????????????????.
        if($removeExtention){
            return substr($relative, 0, strrpos($relative, '.'));
        }

        return $realPath;
    }


    /**
     * ?????????????????????????????????????????????.
     */
    public static function RealPath($contentPath, $extention = null, $normalized = true){
        if($extention === null){
            $extention = self::EXTENTION;
        }

        if($normalized){
            return realpath(CONTENTS_HOME_DIR . "/" . $contentPath .  $extention);
        }
        
        return CONTENTS_HOME_DIR . "/" . $contentPath .  $extention;
    }


    /**
     * ????????????Home?????????????????????????????????.
     */
    public static function RelativePath($dst) {
        switch (false) {
            case $src = CONTENTS_HOME_DIR:
            case $dst = realpath($dst):
            case $src = explode(DIRECTORY_SEPARATOR, $src):
            case $dst = explode(DIRECTORY_SEPARATOR, $dst):
            case $src[0] === $dst[0]:
                return false;
        }
        //var_dump($dst);
        //var_dump($src);
        $cmp =
            DIRECTORY_SEPARATOR === '\\' ?
            'strcasecmp' :
            'strcmp'
        ;

        for (
            $i = 0;
            isset($src[$i], $dst[$i]) && !$cmp($src[$i], $dst[$i]);
            ++$i
        );


        return implode(
            '/',
            array_merge(
                array('.'),
                ($count = count($src) - $i) ?
                    array_fill(0, $count, '..') :
                    array()
                ,
                array_slice($dst, $i)
            )
        );
        // return implode(
        //     DIRECTORY_SEPARATOR,
        //     array_merge(
        //         array('.'),
        //         ($count = count($src) - $i) ?
        //             array_fill(0, $count, '..') :
        //             array()
        //         ,
        //         array_slice($dst, $i)
        //     )
        // );
    }
}
