<?php
    
    class Komus implements IGrabber
    {
        const TAG = 1;
        private $link;
        private $pattern = array('0'=>60,'1'=>2,'2'=>3,'3'=>4,'4'=>5,'5'=>43,'6'=>7,'7'=>8,'8'=>9,'9'=>10,'a'=>11,'b'=>12,'c'=>13,'d'=>20,'e'=>15,'f'=>16);
        private $imgl;
        private $balancer = NULL;
        
        private $process;
        private $log = array();

        public function __construct($dblink,$balancer)
        {
            $this->link = $dblink;
            $this->imgl = imagecreatefromjpeg(DIR_IMAGE."cache/grabber/logox1600.jpg");
            
            $this->process = true;
            $this->balancer = $balancer;
        }
        
        private function fgc($url)
        {
            $curl = curl_init($url);
            $cookie = DIR_LOGS.'/cookies.txt';
            curl_setopt($curl,CURLOPT_COOKIEJAR,$cookie);
            curl_setopt($curl,CURLOPT_COOKIEFILE,$cookie);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
            curl_setopt($curl,CURLOPT_FOLLOWLOCATION,true);
            $return = curl_exec($curl);
            curl_close($curl);
            return $return;
        }
        
        private function processCategory($name,$parentid,&$outcatid,$url)
        {
            $md5n = mb_strtoupper($name,'UTF-8');
            mysqli_ping($this->link);
            $stmt = mysqli_stmt_init($this->link);
            $stmt->prepare("SELECT `id` FROM `grab_categories` WHERE `tag`=? AND `md5`=? AND `parent_id`=?");
            
            $var1 = self::TAG;
            $var2 = md5($md5n);
            
            $stmt->bind_param('isi',$var1,$var2,$parentid);
            $stmt->execute();
            $stmt->store_result();

            if(mysqli_stmt_num_rows($stmt) > 0)
            {
                $stmt->bind_result($outcatid);
                $stmt->fetch();
                $stmt->free_result();
                
                $stmt->close();
                
                $stmt = mysqli_stmt_init($this->link);
                $stmt->prepare("UPDATE `grab_categories` SET `url`=?,`updated`=NOW() WHERE `id`=?");
                $stmt->bind_param('si',$url,$outcatid);
                $stmt->execute();
                
            }else{
                $stmt->free_result();
                $stmt->close();
                
                $stmt = mysqli_stmt_init($this->link);
                $stmt->prepare("INSERT INTO `grab_categories`(`id`,`name`,`md5`,`tag`,`parent_id`,`url`,`updated`) VALUES(NULL,?,?,?,?,?,NOW());");
                $stmt->bind_param('ssiis',$name,$var2,$var1,$parentid,$url);
                $stmt->execute();
                $outcatid = mysqli_stmt_insert_id($stmt);
            }
            
            $stmt->close();
        }
        
        private function recursiveProcessCategory($url,$catid)
        {
            $cid = 0;
            
            if(!$this->balancer->mode())
            {
                //$this->log[] = 'Восстанавливаем состояние';
                $this->balancer->nextLevel();
                $level = $this->balancer->getCurLevel();
                $content = $level['work'];
            }else{
            
                //$this->log[] = 'Получаем контент';
                $content = $this->fgc($url);
            
            
                if(mb_strpos($content,'<div class="goods-kind--inside">',0,'utf-8') !== FALSE)
                    return;
                
                $content = explode('<h2 class="catalog--ag-head">',$content);
                array_shift($content);

                $this->balancer->enterLevel(array('work'=>$content));
            }
            
            if(!$this->balancer->mode() && $this->balancer->isTopLevel() && !$this->balancer->isitem('cur'))
            {
                $this->balancer->setWorkMode();
            }
            
            //$this->log[] = 'isitem:'.($this->balancer->isitem('cur'));
            
            //if(!$this->balancer->mode())
                //$this->log[] = 'Ищем категорию с md5:'.$level['cur'];
            
            foreach($content as $item)
            {
                $item = explode('</span',$item);
                $item = explode('<span',$item[0]);
                $item = explode('>',$item[0]);
                
                $itemname = trim($item[1]);
                
                if(preg_match('/"([^"]+)/',$item[0],$arr))
                {
                    $url = 'http://www.komus.ru'.str_replace('http://www.komus.ru','',$arr[1]);
                    
                    if(!$this->balancer->mode())
                    {
                        //$this->log[] = 'Проверяем категорию:'.$itemname;
                        if($level['cur'] != md5($itemname))
                            continue;
                        
                        //$this->log[] = 'Нашли категорию:'.$itemname;
                        $cid = $this->balancer->gitem('cid');
                    }
                    
                    if($this->balancer->mode())
                    {
                        //$this->log[] = 'Обрабатываем категорию:'.$itemname;
                        $this->processCategory($itemname,$catid,$cid,$arr[1]);
                        $this->balancer->item('cur',md5($itemname));
                        $this->balancer->item('cid',$cid);
                        $this->process = $this->balancer->process();
                    }
                    
                    if(!$this->process)
                        break;

                    if($this->balancer->isTopLevel())
                    {
                        $this->balancer->setWorkMode();
                    }

                    
                    //$this->log[] = 'Спускаемся ниже';
                    $this->recursiveProcessCategory($url,$cid);
                    
                    if(!$this->process)
                        break;
                }
            }
            $this->balancer->leaveLevel();
        }
        
        public function collectCategories()
        {
            $catid = 0;
            
            //$this->log[] = 'Начинаем работу';
            
            if(!$this->balancer->mode())
            {
                //$this->log[] = 'Восстанавливаем состояние';
                $this->balancer->nextLevel();
                $level = $this->balancer->getLevel(0);
                
                if(!isset($level['tag']) || (int)$level['tag'] < self::TAG)
                {
                    $content = $this->fgc('http://www.komus.ru/catalog/');
                    $content = explode('<h2><a href="',$content);
                    array_shift($content);
                    $this->balancer->enterLevel(array('tag'=>self::TAG,'work'=>$content));
                }
                
                if(isset($level['tag']) && (int)$level['tag'] == self::TAG)
                {
                    $content = $level['work'];
                }
                
                if(isset($level['tag']) && $level['tag'] > self::TAG)
                {
                    $this->balancer->prevLevel();
                    return;
                }
            }else{
            
                //$this->log[] = 'Первый запуск';
                $content = $this->fgc('http://www.komus.ru/catalog/');
                $content = explode('<h2><a href="',$content);
                array_shift($content);
                $this->balancer->enterLevel(array('tag'=>self::TAG,'work'=>$content));
            }
            
            $lastitem = false;
            $itemmd5 = md5(end($content));
            
            //if(!$this->balancer->mode())
            //$this->log[] = 'Ищем категорию с md5:'.$level['cur'];
            
            foreach($content as $item)
            {
                $itemmd52 = md5($item);
                $item = explode('</span',$item);
                $item = array_shift($item);
                
                $itemname = explode('>',$item);
                $itemname = array_pop($itemname);
                
                if(preg_match('/([^"]+)/',$item,$arr))
                {
                    if(!$this->balancer->mode())
                    {
                        //$this->log[] = 'Проверяем категорию:'.$itemname;
                        if(md5($itemname) != $level['cur'])
                            continue;
                        
                        //$this->log[] = 'Нашли:'.$itemname;
                        $catid = $this->balancer->gitem('cid');
                    }
                    
                    if($this->balancer->mode())
                    {
                        //$this->log[] = 'Обрабатываем категорию:'.$itemname;
                        $this->processCategory($itemname,0,$catid,$arr[1]);
                        $this->balancer->item('cur',md5($itemname));
                        $this->balancer->item('cid',$catid);
                        $this->process = $this->balancer->process();
                    }
                    
                    if(!$this->process)
                        break;
                        
                    if($this->balancer->isTopLevel())
                    {
                        $this->balancer->setWorkMode();
                    }

                    //$this->log[] = 'Рекурсия вниз';
                    $this->recursiveProcessCategory('http://www.komus.ru'.$arr[1],$catid);
                    
                    /*
                    if($this->process && $itemmd52 == $itemmd5)
                    {
                        //die(json_encode($this->log));
                        die('1');
                    }
                    */
                    if(!$this->process)
                        break;
                }
            }
            
            $this->balancer->leaveLevel();
            //die(json_encode($this->log));
            //die('0');
            //var_dump($this->balancer);
            //die('KOMUS collected');
        }
        
        private function buildPath($cid,$parentid)
        {
            $arr = array();
            $level = 0;
            
            $stmt = mysqli_stmt_init($this->link);
            $stmt->prepare("SELECT `path_id` FROM `category_path` WHERE `category_id`=? ORDER BY level;");
            
            $stmt->bind_param('i',$parentid);
            
            $stmt->execute();
            $stmt->store_result();
            
            if($stmt->num_rows() > 0)
            {
                $pid = 0;
                $stmt->bind_result($pid);
                
                while($stmt->fetch())
                {
                    $arr[] = $pid;
                }
                
                $stmt->free_result();
            }
            
            $arr[] = $cid;
            
            foreach($arr as $pid)
            {
                $stmt2 = mysqli_stmt_init($this->link);
                $stmt2->prepare("INSERT INTO `category_path`(`category_id`,`path_id`,`level`) VALUES(?,?,?)");
                $stmt2->bind_param('iii',$cid,$pid,$level);
                $stmt2->execute();
                $stmt2->close();
                $level++;
            }
                
            $stmt->close();
        }
        
        private function checkAndRebuildStruct($cid,$occid)//$occid - категория opencart на которую отображается категория $cid
        {
            $tag = self::TAG;

            $stmt = mysqli_stmt_init($this->link);
            
            $stmt->prepare("UPDATE `grab_categories` SET `oc_cid`=? WHERE `tag`=? AND `id`=?");
            $stmt->bind_param('iii',$occid,$tag,$cid);
            $stmt->execute();
            $stmt->close();
            
            $stmt = mysqli_stmt_init($this->link);
            
            $stmt->prepare("SELECT `id`,`name`,`md5` FROM `grab_categories` WHERE `tag`=? AND `parent_id`=?;");
            $stmt->bind_param('ii',$tag,$cid);
            
            $stmt->execute();
            
            $stmt->store_result();
            
            if($stmt->num_rows() > 0)
            {
                $id = $name = $md5 = 0;
                
                $stmt->bind_result($id,$name,$md5);
                
                while($stmt->fetch())
                {
                    $stmt2 = mysqli_stmt_init($this->link);
                    
                    $stmt2->prepare("SELECT c.`category_id` FROM `category` as c LEFT JOIN `category_description` as cd ON cd.`category_id`=c.`category_id` WHERE c.`parent_id`=? AND cd.`language_id`=4 AND MD5(UPPER(cd.`name`))=?;");
                    $stmt2->bind_param('is',$occid,$md5);
                    
                    $stmt2->execute();
                    $stmt2->store_result();

                    if($stmt2->num_rows() == 0)
                    {
                        $lang = 4;//RU
                        //нужно создать категорию
                        $stmt2->close();
                        
                        $stmt2 = mysqli_stmt_init($this->link);
                        $stmt2->prepare("INSERT INTO `category`(`category_id`,`image`,`parent_id`,`top`,`column`,`sort_order`,`status`,`date_added`,`date_modified`) VALUES(NULL,'',?,0,1,0,1,NOW(),NOW());");
                        $stmt2->bind_param('i',$occid);
                        
                        $stmt2->execute();
                        
                        $occid2 = mysqli_stmt_insert_id($stmt2);
                        
                        $stmt2->close();
                        
                        $stmt2 = mysqli_stmt_init($this->link);
                        $stmt2->prepare("INSERT INTO `category_description`(`category_id`,`language_id`,`name`,`description`,`meta_description`,`meta_keyword`) VALUES(?,?,?,'','','');");
                        $stmt2->bind_param('iis',$occid2,$lang,$name);
                        $stmt2->execute();
                        
                        $stmt2->close();
                        
                        $lang = 1;//EN
                        
                        $stmt2 = mysqli_stmt_init($this->link);
                        $stmt2->prepare("INSERT INTO `category_description`(`category_id`,`language_id`,`name`,`description`,`meta_description`,`meta_keyword`) VALUES(?,?,?,'','','');");
                        $stmt2->bind_param('iis',$occid2,$lang,$name);
                        $stmt2->execute();
                        
                        $stmt2->close();
                        
                        $stmt2 = mysqli_stmt_init($this->link);
                        $stmt2->prepare("INSERT INTO `category_to_store`(`category_id`,`store_id`) VALUES(?,0);");
                        $stmt2->bind_param('i',$occid2);
                        $stmt2->execute();
                        
                        $this->buildPath($occid2,$occid);
                    }else{
                        $occid2 = 0;
                        
                        $stmt2->bind_result($occid2);
                        $stmt2->fetch();
                        $stmt2->free_result();
                    }
                    
                    $stmt2->close();
                    
                    $this->checkAndRebuildStruct($id,$occid2);
                }

                $stmt->free_result();
            }
            
            $stmt->close();
        }
        
        private function getProduct($md5,&$md5simg,&$gid,&$tbln,&$key)
        {
            $res = 0;
            
            for($i = 0; $i < 32; $i++)
            {
                $res += $this->pattern[$md5[$i]];
            }
            
            $table = $res % 255;
            $table++;
            $tbln = $table;
            $key = $res;
            //$res = key, table - table in database
            
            $stmt = mysqli_stmt_init($this->link);
            $stmt->prepare("SELECT `id`,`pid`,`md5simg` FROM grab_p".$table." WHERE `key`=? AND `md5`=?");
            $stmt->bind_param('is',$res,$md5);
            
            $stmt->execute();
            $stmt->store_result();
            
            if($stmt->num_rows() == 0)
            {
                $stmt->close();
                return 0;
            }
            
            $pid = 0;
            $stmt->bind_result($gid,$pid,$md5simg);
            $stmt->fetch();
            $stmt->free_result();
            $stmt->close();
            return $pid;
        }
        
        private function downloadImage($imgname,$url,$deleteold)
        {
            if($deleteold)
            {
                if(file_exists(DIR_IMAGE_KOMUS.$imgname.'.jpg'))
                    unlink(DIR_IMAGE_KOMUS.$imgname.'.jpg');
                if(file_exists(DIR_IMAGE_SIMA.$imgname.'.png'))
                    unlink(DIR_IMAGE_KOMUS.$imgname.'.png');
                if(file_exists(DIR_IMAGE_KOMUS.$imgname.'.gif'))
                    unlink(DIR_IMAGE_KOMUS.$imgname.'.gif');
            }
            
            $fname = DIR_IMAGE_KOMUS.$imgname;
            $tmp = explode('.',$url);
            
            switch($tmp[count($tmp)-1])
            {
                case 'jpeg':
                case 'jpg':
                    $ext = 'jpg';
                break;
                
                case 'gif':
                    $ext = 'gif';
                break;
                
                case 'png':
                    $ext = 'png';
                break;
                
                default:
                    return '';
            }
            
            $fname .= '.'.$ext;
            file_put_contents($fname,$this->fgc($url));
            $imginfo = getimagesize($fname);
            
            switch($ext)
            {
                case 'jpg':
                    $imgsrc = imagecreatefromjpeg($fname);
                break;
                
                case 'png':
                    $imgsrc = imagecreatefrompng($fname);
                break;
                
                case 'gif':
                    $imgsrc = imagecreatefromgif($fname);
                break;
            }
            
            imagecopy($imgsrc,$this->imgl,40,$imginfo[1]-131,0,0,270,131);
            
            switch($ext)
            {
                case 'jpg':
                    imagejpeg($imgsrc,$fname,80);
                break;
                
                case 'png':
                    imagepng($imgsrc,$fname,8);
                break;
                case 'gif':
                    imagegif($imgsrc,$fname);
                break;
            }
            imagedestroy($imgsrc);
            
            $fname = str_replace(DIR_IMAGE_KOMUS,"",$fname);
            $fname = 'cache/grabber/komus/'.$fname;
            return $fname;
        }
        
        private function processProduct($cid,$occid,$simgurl,$bigimgurl,$name,$desc,$cost,$costd,$noimg)
        {
            $md5n = md5(mb_strtoupper($name));
            $gid = 0;
            $key = 0;
            $md5simg = $nimg = '';
            $tbl = 0;
            
            $pid = $this->getProduct($md5n,$md5simg,$gid,$tbl,$key);
            
            
            if(!$noimg)
            {

                $newmd5simg = md5($this->fgc($simgurl));

                if($gid != 0)
                {
                    $nimg = $this->downloadImage($newmd5simg,$bigimgurl,false);
                }else{
                    if($newmd5simg != $md5simg)
                    {
                        $nimg = $this->downloadImage($newmd5simg,$bigimgurl,true);
                    }
                }
            }else{
                $newmd5simg = '';
            }
            
            $stmt = mysqli_stmt_init($this->link);
            
            if($gid != 0)
            {
                $stmt->prepare("UPDATE `grab_p".$tbl."` SET `cost1`=?,`cost1d`=?,`desc`=?,`md5simg`=?,`updated`=1 WHERE `id`=?;");
                $stmt->bind_param('ddssi',$cost,$costd,$desc,$newmd5simg,$gid);
            }else{
                $stmt->prepare("INSERT INTO `grab_p".$tbl."`(`id`,`updated`,`key`,`md5`,`md5simg`,`cost1`,`cost1d`,`name`,`desc`,`min`,`new`) VALUES(NULL,1,?,?,?,?,?,?,?,1,1);");
                $stmt->bind_param('issddss',$key,$md5n,$newmd5simg,$cost,$costd,$name,$desc);
            }
            
            $stmt->execute();
            
            if($gid == 0)
            {
                $gid = mysqli_stmt_insert_id($stmt);
            }
            $stmt->close();
            
            $stmt = mysqli_stmt_init($this->link);
            $stmt->prepare("SELECT 1 FROM `grab_p2c` WHERE `gid`=? AND `tbl`=? AND `cid`=?;");
            $stmt->bind_param('iii',$gid,$tbl,$occid);
            $stmt->execute();
            $stmt->store_result();
            
            if($stmt->num_rows() > 0)
            {
                $stmt->free_result();
            }else{
                $stmt2 = mysqli_stmt_init($this->link);
                $stmt2->prepare("INSERT INTO `grab_p2c`(`gid`,`tbl`,`cid`) VALUES(?,?,?);");
                $stmt2->bind_param('iii',$gid,$tbl,$occid);
                $stmt2->execute();
                $stmt2->close();
            }
            $stmt->close();
        }
        
        private function grabProduct($url,$cid,$occid,$name,$simgurl,$noimg)
        {
            $content = $this->fgc($url);
            
            $cost = $costd = 0;
            $desc = '';
            $bigimgurl = '';
            
            if(strpos($content,'<table class="product-card--opt-table">') !== false)
            {
                
                $tmp = explode('<table class="product-card--opt-table">',$content);
                $tmp = explode('</table>',$tmp[1]);
                $tmp = explode('<td class="product-card--opt-table-td product-card--opt-table-td-price">',$tmp[0]);
                
                array_shift($tmp);
                $tmp = $tmp[0];
                $tmp = explode('</td>',$tmp);
                $tmp = array_shift($tmp);
                
                $cost = (double)str_replace(',','.',implode('',explode(' ',$tmp)));
                
            }else{

                $tmp = explode('<span class="product-card--price-old-value">',$content);

                if(count($tmp) > 1)
                {
                    $tmp = explode('</span>',$tmp[1]);
                    $tmp[0] = implode('',explode(' ',$tmp[0]));
                    $costd = (double)str_replace(',','.',$tmp[0]);
                }

                $tmp = explode('<span class="product-card--price-now-value">',$content);

                if(count($tmp) == 1)
                    return;//не нашли цену?

                $tmp = explode('</span>',$tmp[1]);
                $tmp[0] = implode('',explode(' ',$tmp[0]));
                $cost = (double)str_replace(',','.',$tmp[0]);

            }
            
            $tmp = explode('<div class="product-description--description-inside">',$content);
            
            if(count($tmp) > 1)
            {
                $tmp = explode('</div>',$tmp[1]);
                $desc = trim($tmp[0]);
            }
            
            if(!$noimg)
            {
            
                $tmp = explode('<div class="product-card--picture-block">',$content);
                array_shift($tmp);
                $tmp = explode('</a>',$tmp[0]);
            
                if(preg_match('/<a.+href="([^"]+)"[^>]*>/',$tmp[0],$arr))
                {
                    $bigimgurl = 'http://www.komus.ru'.str_replace('http://www.komus.ru','',$arr[1]);
                }
            }
            $this->processProduct($cid,$occid,$simgurl,$bigimgurl,$name,$desc,$cost,$costd,$noimg);
            $this->process = $this->balancer->process();
        }
        
        private function parseProducts(&$content,$cid,$occid)
        {
            if(!$this->process)
                return;

            
            if(!$this->balancer->mode())
            {
                $this->balancer->nextLevel();
                $level = $this->balancer->getCurLevel();
            }else{
                $this->balancer->enterLevel(array());
            }
            
            $tmp = explode('<div class="goods-table">',$content);
            array_shift($tmp);
            $tmp2 = array_pop($tmp);
            
            $tmp2 = explode('<div class="button-favorites button-favorites_js">',$tmp2);
            $tmp2 = $tmp2[0];
            array_push($tmp,$tmp2);
            unset($tmp2);
            
            if(!$this->balancer->mode() && !$this->balancer->isitem('cur') && $this->balancer->isTopLevel())
                $this->balancer->setWorkMode();
            
            foreach($tmp as $item)
            {
                
                if(!$this->balancer->mode())
                {
                    if($level['cur'] != md5($item))
                        continue;
                }
                
                $simgurl = '';
                $name = '';
                $produrl = '';
                $noimg = false;
                
                if(preg_match('/<img.+[^\/]+\/>/',$item,$arr))
                {
                    $p = $arr[0];
                    
                    if(preg_match('/alt="([^"]+)"/',$p,$arr))
                    {
                        $name = $arr[1];
                    }
                    
                    if(preg_match('/src="([^"]+)"/',$p,$arr))
                    {
                        $simgurl = 'http://www.komus.ru'.str_replace('http://www.komus.ru','',$arr[1]);
                        
                        if(strpos($simgurl,'_normal_noimage.jpg') !== false)
                        {
                            $noimg = true;
                        }
                    }
                }
                
                if(preg_match_all('/<a[^>]+>/',$item,$arr))
                {
                    foreach($arr[0] as $v)
                    {
                        if(preg_match('/class="goods-table--name-link"/',$v))
                        {
                            if(preg_match('/href="([^"]+)"/',$v,$arr2))
                            {
                                $produrl = $arr2[1];
                            }
                        }
                    }
                }
                
                if(!empty($name) && !empty($produrl))
                {
                    $this->grabProduct($produrl,$cid,$occid,$name,$simgurl,$noimg);
                    //echo "process:".$produrl."<br/>".$name."<br/>";
                }
                
                $this->balancer->item('cur',md5($item));
                
                if(!$this->balancer->mode())
                    $this->balancer->setWorkMode();

                if(!$this->process)
                    break;
            }

            $this->balancer->leaveLevel();
        }
        
        private function grabProducts($url,$cid,$occid)
        {
            $url = 'http://www.komus.ru'.str_replace('http://www.komus.ru','',$url);
            
            if(!$this->balancer->mode())
            {
                $this->balancer->nextLevel();
                $level = $this->balancer->getCurLevel();
                $larr = $level['work'];
            }else{
            
                $larr = array();

                $stmt = mysqli_stmt_init($this->link);
                $stmt->prepare("SELECT `id`,`oc_cid`,`url` FROM `grab_categories` WHERE `parent_id`=?");
                $stmt->bind_param('i',$cid);

                $stmt->execute();
                $stmt->store_result();

                if($stmt->num_rows() > 0)
                {
                    $cid2 = $occid2 = 0;
                    $url2 = '';

                    $stmt->bind_result($cid2,$occid2,$url2);

                    while($stmt->fetch())
                    {
                        $larr[] = array('cid'=>$cid2,'occid'=>$occid2,'url'=>$url2);
                    }
                }
            
                $stmt->close();
                
                $this->balancer->enterLevel(array('work'=>$larr));
            
            }
            
            foreach($larr as $item)
            {
                if(!$this->balancer->mode())
                {
                    if($level['cur'] != $item['cid'])
                        continue;
                }
                
                $this->balancer->item('cur',$item['cid']);
                $this->grabProducts($item['url'],$item['cid'],$item['occid']);
                
                if(!$this->process)
                {
                    $this->balancer->leaveLevel();
                    return;
                }
            }

            $content = $this->fgc($url);
            
            if(strpos($content,'<div class="goods-table">') === FALSE)
            {
                $url = explode('/',$url);
                $last = array_pop($url);
                
                if(!empty($last))
                    array_push($url,$last);
                array_push($url,'_s');
                array_push($url,'vision');
                array_push($url,'table');
                $url = implode('/',$url).'/';
                
                $content = $this->fgc($url);
            }

            //ищем список товаров
            $tmp = explode('<div class="goods-table">',$content);
            
            if(count($tmp) == 1)
            {
                $this->balancer->leaveLevel();
                return;//обрабатываем только категории, где представлены товары
            }
            
            //ищем список страниц
            $pages = array();
            $tmp = explode('<div class="pagination">',$content);
            
            if(count($tmp) > 1)
            {
                array_shift($tmp);
                $tmp = explode('</div>',$tmp[0]);
                $tmp = $tmp[0];
                
                $max = 0;
                
                if(preg_match_all('/<a.+href="([^"]+)/',$tmp,$arr))
                {
                    $pattern = $arr[1][0];
                    
                    foreach($arr[1] as $k=>$v)
                    {
                        if(preg_match('/\/([0-9]+)\/$/',$v,$arr2))
                        {
                            if((int)$arr2[1] > $max)
                                $max = (int)$arr2[1];
                        }
                    }
                    
                    for($i = 2; $i <= $max; $i++)
                    {
                        $pages[] =  'http://www.komus.ru'.str_replace('http://www.komus.ru','',preg_replace('/\/([0-9]+)\/$/','/'.$i.'/',$pattern));
                    }
                }
            }
            
            $pagei = 0;
            $need = true;
            
            do
            {
                if(!$this->process)
                    break;

                if(!$this->balancer->mode() && isset($level['page']) && (int)$level['page'] > 0)
                {
                    if($level['page'] != $pagei)
                    {
                        $pagei++;
                        $nitem = array_shift($pages);
                        continue;
                    }
                    
                    $content = $this->fgc($nitem);
                }

                $this->balancer->item('page',$pagei);
                $this->parseProducts($content,$cid,$occid);
                
                if(!$this->process)
                    break;
                
                if(count($pages) > 0)
                {
                    $nitem = array_shift($pages);
                    $content = $this->fgc($nitem);
                }else{
                    $need = false;
                }
                
                $pagei++;
            }while($need);
            
            $this->balancer->leaveLevel();
        }
        
        public function collectProducts()
        {
            header('Content-type: text/html;charset=utf-8');
          
            if($this->balancer->processed())
                return;
            
            if(!$this->balancer->mode())
            {
                $this->balancer->nextLevel();
                $level = $this->balancer->getLevel(0);
                
                if((int)$level['tag'] > self::TAG)
                {
                    $this->balancer->prevLevel();
                    return;
                }
                
                $larr = $level['work'];
            }else{
            
                $tag = self::TAG;
                $larr = array();
                $stmt = mysqli_stmt_init($this->link);
                $stmt->prepare("SELECT cs.`cid`,cs.`oc_cid`,c.`url` FROM `grab_cat_settings` as cs LEFT JOIN `grab_categories` as c ON cs.cid=c.id WHERE cs.`tag`=?;");
                $stmt->bind_param('i',$tag);

                $stmt->execute();
                $stmt->store_result();

                if($stmt->num_rows() > 0)
                {
                    $cid = $occid = 0;
                    $url = '';

                    $stmt->bind_result($cid,$occid,$url);
                    while($stmt->fetch())
                    {
                        $larr[] = array('cid'=>$cid,'occid'=>$occid,'url'=>$url);
                    }
                    $stmt->free_result();
                }

                $stmt->close();
                $this->balancer->enterLevel(array('tag'=>self::TAG,'work'=>$larr));
            }
            
            foreach($larr as $item)
            {
                if(!$this->balancer->mode())
                {
                    if($level['cur'] != $item['cid'])
                        continue;
                }
                
                if($this->balancer->mode())
                {
                    $this->checkAndRebuildStruct($item['cid'],$item['occid']);
                }
                
                $this->balancer->item('cur',$item['cid']);
                
                $this->grabProducts($item['url'],$item['cid'],$item['occid']);
                
                if(!$this->process)
                    break;
            }
            
            $this->balancer->leaveLevel();
        }
              
        public function parsePre()
        {
            //
        }
        
        public function parsePost()
        {
            //
        }
    }
    
?>