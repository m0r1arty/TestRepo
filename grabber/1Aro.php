<?php
    
    class Aro implements IGrabber
    {
        const TAG = 3;
        const NOIMG = 'http://www.aro-market.ru/templates/pictures/no_image.jpg';
        
        private $link;
        private $cats = array(
        'root'=>array('name'=>'Парфюмерия','ch'=>
                    array(
                        //array('name'=>'Аксессуары','url'=>'http://www.aro-market.ru/shop/type/24/'),
                        array('name'=>'Дезодорант','url'=>'http://www.aro-market.ru/shop/type/26/','spec'=>1),
                        array('name'=>'Духи','url'=>'http://www.aro-market.ru/shop/type/9/','spec'=>2),
                        array('name'=>'Одеколон','url'=>'http://www.aro-market.ru/shop/type/6/','spec'=>3),
                        array('name'=>'Парфюмированная вода','url'=>'http://www.aro-market.ru/shop/type/1/','spec'=>4),
                        array('name'=>'Туалетная вода','url'=>'http://www.aro-market.ru/shop/type/3/','spec'=>5),
                        array('name'=>'Уход','url'=>'http://www.aro-market.ru/shop/type/22/','spec'=>6),
                        array('name'=>'Тестер','url'=>'http://www.aro-market.ru/shop/type/8,7,2,4/','spec'=>7)
                    )
                )
        );
        
        public function __construct($dblink)
        {
            $this->link = $dblink;
        }
        
        private function processCategory($name,$parentid,&$outcatid,$url,$special)
        {
            $md5n = mb_strtoupper($name,'UTF-8');
            $stmt = mysqli_stmt_init($this->link);
            $stmt->prepare("SELECT id FROM grab_categories WHERE tag=? AND md5=? AND parent_id=?");
            
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

            }else{
                $stmt->free_result();
                $stmt->close();
                
                $stmt = mysqli_stmt_init($this->link);
                $stmt->prepare("INSERT INTO grab_categories(`id`,`name`,`md5`,`tag`,`parent_id`,`url`) VALUES(NULL,?,?,?,?,?);");
                $stmt->bind_param('ssiis',$name,$var2,$var1,$parentid,$url);
                $stmt->execute();
                $outcatid = mysqli_stmt_insert_id($stmt);
                
                $stmt->close();
                
                $stmt = mysqli_stmt_init($this->link);
                $stmt->prepare("INSERT INTO grab_special(`id`,`gid`,`spec1`) VALUES(NULL,?,?);");
                $stmt->bind_param('ii',$outcatid,$special);
                $stmt->execute();
            }
            
            $stmt->close();
        }
        
        private function processBrands($url,$parent_id,$spec)
        {
            $cid = 0;
            $content = file_get_contents($url);
            $content = explode('<table width="100%" class="brands">',$content);
            array_shift($content);
            $content = explode('</table>',$content[0]);
            $content = array_shift($content);
            
            if(preg_match_all('/<a+.href="([^"]+)".+class="brand"[^>]*>([^<]+)</',$content,$arr))
            {                    
                foreach($arr[2] as $k=>$v)
                {
                    $v = iconv('windows-1251','utf-8',$v);
                    $this->processCategory($v,$parent_id,$cid,$arr[1][$k],$spec);
                }
            }
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
        
        private function checkAndRebuildStruct($cid,$occid)
        {
            $tag = self::TAG;

            $stmt = mysqli_stmt_init($this->link);
            
            $stmt->prepare("UPDATE `grab_categories` SET oc_cid=? WHERE tag=? AND id=?");
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
        
        private function processProduct($cid,$occid,$fullname,$cost,$noimg,$img,$desc)
        {
            $oldmd5simg = '';
            $md5 = md5(mb_strtoupper($fullname,'utf-8'));
            $stmt = mysqli_stmt_init($this->link);
            $stmt->prepare("SELECT `id`,`md5simg` FROM `grab_products` WHERE `gcid3`=? AND `md5`=?");
            $stmt->bind_param('is',$cid,$md5);
            $stmt->execute();
            $stmt->store_result();

            if($stmt->num_rows() > 0)
            {
                $grabid = 0;
                $ocpid = 0;
                $md5 = '';
                
                $stmt->bind_result($grabid,$oldmd5simg);
                $stmt->fetch();
                $stmt->free_result();
                
                $stmt->close();
                
                $stmt = mysqli_stmt_init($this->link);
                $stmt->prepare("SELECT `product_id` FROM `product_description` WHERE MD5(UPPER(`name`))=?");
                $md5 = md5(mb_strtoupper($fullname,'utf-8'));
                $stmt->bind_param('s',$md5);
                $stmt->execute();
                $stmt->store_result();
                
                if($stmt->num_rows() > 0)
                {
                    $status = 0;
                    //только обновляем товар
                    $stmt->bind_result($ocpid);
                    $stmt->fetch();
                    $stmt->free_result();
                    $stmt->close();

                    //обновляем цену в обоих таблицах
                    
                    if(!empty($cost))
                    {
                        $stmt = mysqli_stmt_init($this->link);
                        $stmt->prepare("UPDATE `product` SET `price`=?,`status`=1 WHERE `product_id`=?;");
                        $stmt->bind_param('di',$cost,$ocpid);
                        $stmt->execute();
                        $stmt->close();
                        
                        $stmt = mysqli_stmt_init($this->link);
                        $stmt->prepare('UPDATE `grab_products` SET `cost3`=?,`costd3`=0 WHERE `id`=?');
                        $stmt->bind_param('di',$cost,$grabid);
                        $stmt->execute();
                        $stmt->close();
                        
                    }else{
                        $stmt = mysqli_stmt_init($this->link);
                        $stmt->prepare("UPDATE `product` SET `price`=0,`status`=0 WHERE `product_id`=?");
                        $stmt->bind_param('i',$ocpid);
                        $stmt->execute();
                        $stmt->close();
                        
                        $stmt = mysqli_stmt_init($this->link);
                        $stmt->prepare("UPDATE `grab_products` SET `cost3`=0,`costd3`=0 WHERE id=?");

                        if(!$stmt->bind_param('i',$grabid))
                            die(mysqli_stmt_error($stmt));

                        $stmt->execute();
                        $stmt->close();
                    }
                    
                    //на всякий случай обновляем $desc
                    
                    $stmt = mysqli_stmt_init($this->link);
                    $stmt->prepare("UPDATE `product_description` SET `description`=? WHERE `product_id`=?");
                    $stmt->bind_param('si',$desc,$ocpid);
                    $stmt->execute();
                    $stmt->close();
                    
                    //если изменилось изображение
                    if($noimg == false && !empty($img))
                    {
                        $stmt = mysqli_stmt_init($this->link);
                        $stmt->prepare("SELECT `md5simg` FROM `grab_products` WHERE `id`=?");
                        $stmt->bind_param('i',$grabid);
                        $stmt->execute();
                        $stmt->store_result();
                        
                        if($stmt->num_rows() > 0)
                        {
                            $md5simg = 0;
                            $stmt->bind_result($md5simg);

                            $stmt->fetch();

                            $stmt->free_result();
                            
                            $imgsrc = file_get_contents($img);
                            $md5simgnew = md5($imgsrc);

                            //обновляем, перезагружаем
                            if($md5simg != $md5simgnew)
                            {
                                if(file_exists(DIR_IMAGE_ARO.$md5simg.'.jpg'))
                                {
                                    unlink(DIR_IMAGE_ARO.$md5simg.'.jpg');
                                }
                                if(file_exists(DIR_IMAGE_ARO.$md5simg.'.png'))
                                {
                                    unlink(DIR_IMAGE_ARO.$md5simg.'.png');
                                }
                                if(file_exists(DIR_IMAGE_ARO.$md5simg.'.gif'))
                                {
                                    unlink(DIR_IMAGE_ARO.$md5simg.'.gif');
                                }
                                $nimg = '';
                                file_put_contents(DIR_IMAGE_ARO.$md5simgnew,$imgsrc);
                                $imginfo = getimagesize(DIR_IMAGE_ARO.$md5simgnew);
                                
                                switch($imginfo[2])
                                {
                                    case IMG_JPEG:
                                    case IMG_JPG:

                                        $nimg = $md5simgnew.'.jpg';
                                        rename(DIR_IMAGE_ARO.$md5simgnew,DIR_IMAGE_ARO.$md5simgnew.'.jpg');
                                    
                                    break;
                                    
                                    case IMG_PNG:

                                        $nimg = $md5simgnew.'.png';
                                        rename(DIR_IMAGE_ARO.$md5simgnew,DIR_IMAGE_ARO.$md5simgnew.'.png');
                                    
                                    break;

                                    case IMG_GIF:

                                        $nimg = $md5simgnew.'.gif';
                                        rename(DIR_IMAGE_ARO.$md5simgnew,DIR_IMAGE_ARO.$md5simgnew.'.gif');
                                    
                                    break;
                                    
                                    default:
                                    
                                        unlink(DIR_IMAGE_ARO.$md5simgnew);
                                    
                                    break;
                                }
                                
                                if(!empty($nimg))
                                {
                                    //изображение на своем месте, пишем в базу
                                    $stmt = mysqli_stmt_init($this->link);
                                    $stmt->prepare("UPDATE `grab_products` SET `md5simg`=? WHERE `id`=?");
                                    $stmt->bind_param('si',$md5simgnew,$grabid);
                                    $stmt->execute();
                                    $stmt->close();
                                    
                                    $stmt = mysqli_stmt_init($this->link);
                                    $stmt->prepare("UPDATE `product` SET `image`=? WHERE `product_id`=?");
                                    
                                    $nimg = 'cache/grabber/aro/'.$nimg;
                                    
                                    $stmt->bind_param('si',$nimg,$ocpid);
                                    $stmt->execute();
                                    $stmt->close();
                                }
                            }
                        }

                    }else{

                        @unlink(DIR_IMAGE_ARO.$md5simg.'.jpg');
                        @unlink(DIR_IMAGE_ARO.$md5simg.'.png');
                        @unlink(DIR_IMAGE_ARO.$md5simg.'.gif');
                        
                        $stmt = mysqli_stmt_init($this->link);
                        $stmt->prepare("UPDATE `product` SET `image`='' WHERE `product_id`=?");
                        $stmt->bind_param('i',$ocpid);
                        $stmt->execute();
                        $stmt->close();
                        
                        $stmt = mysqli_stmt_init($this->link);
                        $stmt->prepare("UPDATE `grab_products` SET `md5simg`='' WHERE `id`=?");
                        $stmt->bind_param('i',$grabid);
                        $stmt->execute();
                        $stmt->close();

                    }
                }else{
                    //добавляем товар только в opencart
                    $nimg = '';
                    //сначала сверим изображение
                    
                    if($noimg == false && !empty($img))
                    {
                        $imgsrc = file_get_contents($img);
                        $md5simgnew = md5($imgsrc);
                    }
                    
                    if($noimg == false && !empty($img) && $md5simgnew != $oldmd5simg)
                    {
                        //загрузим и получим имя
                        file_put_contents(DIR_IMAGE_ARO.$md5simgnew,$imgsrc);
                        $imginfo = getimagesize(DIR_IMAGE_ARO.$md5simgnew);
                        
                        switch($imginfo[2])
                        {
                            case IMG_JPEG:
                            case IMG_JPG:
                            
                                $nimg = $md5simgnew.'.jpg';
                                rename(DIR_IMAGE_ARO.$md5simgnew,DIR_IMAGE_ARO.$md5simgnew.'.jpg');
                            
                            break;
                            
                            case IMG_PNG:
                            
                                $nimg = $md5simgnew.'.png';
                                rename(DIR_IMAGE_ARO.$md5simgnew,DIR_IMAGE_ARO.$md5simgnew.'.png');
                            break;
                            
                            case IMG_GIF:
                            
                                $nimg = $md5simgnew.'.gif';
                                rename(DIR_IMAGE_ARO.$md5simgnew,DIR_IMAGE_ARO.$md5simgnew.'.gif');
                            
                            break;
                            
                            default:
                                unlink(DIR_IMAGE_ARO.$md5simgnew);
                            break;
                        }
                        
                        if(!empty($nimg))
                        {
                            $stmt = mysqli_stmt_init($this->link);
                            $stmt->prepare("UPDATE `grab_products` SET `md5simg`=? WHERE id=?");
                            $stmt->bind_param('si',$md5simgnew,$grabid);
                            $stmt->execute();
                            $stmt->close();
                            $nimg = 'cache/grabber/aro/'.$nimg;
                        }
                    }else if(!empty($oldmd5simg) && ($noimg == true || empty($img)))
                    {
                        $stmt = mysqli_stmt_init($this->link);
                        $stmt->prepare("UPDATE `grab_products` SET `md5simg`='' WHERE `id`=?");
                        $stmt->bind_param('i',$grabid);
                        $stmt->execute();
                        $stmt->close();
                    }else if($noimg == false && !empty($img) && $md5simgnew == $oldmd5simg)
                    {
                        $nimg = DIR_IMAGE_ARO.$oldmd5simg.'.jpg';
                        
                        if(!file_exists($nimg))
                        {
                            $nimg = DIR_IMAGE_ARO.$oldmd5simg.'.png';
                        }
                        
                        if(!file_exists($nimg))
                        {
                            $nimg = DIR_IMAGE_ARO.$oldmd5simg.'.gif';
                        }
                        
                        if(!file_exists($nimg))
                        {
                            file_put_contents(DIR_IMAGE_ARO.$md5simgnew,$imgsrc);
                            $imginfo = getimagesize(DIR_IMAGE_ARO.$md5simgnew);
                        
                            switch($imginfo[2])
                            {
                                case IMG_JPEG:
                                case IMG_JPG:
                            
                                    $nimg = $md5simgnew.'.jpg';
                                    rename(DIR_IMAGE_ARO.$md5simgnew,DIR_IMAGE_ARO.$md5simgnew.'.jpg');
                                break;

                                case IMG_PNG:
                            
                                    $nimg = $md5simgnew.'.png';
                                    rename(DIR_IMAGE_ARO.$md5simgnew,DIR_IMAGE_ARO.$md5simgnew.'.png');
                                break;

                                case IMG_GIF:
                            
                                    $nimg = $md5simgnew.'.gif';
                                    rename(DIR_IMAGE_ARO.$md5simgnew,DIR_IMAGE_ARO.$md5simgnew.'.gif');
                            
                                break;
                            
                                default:
                                    $nimg = '';
                                    unlink(DIR_IMAGE_ARO.$md5simgnew);
                                break;
                            }
                        }
                        
                        if(!empty($nimg))
                        {
                            $stmt = mysqli_stmt_init($this->link);
                            $stmt->prepare("UPDATE `grab_products` SET `md5simg`=? WHERE id=?");
                            $stmt->bind_param('si',$md5simgnew,$grabid);
                            $stmt->execute();
                            $stmt->close();
                            $nimg = 'cache/grabber/aro/'.$nimg;
                        }
                    }
                    
                    $stmt = mysqli_stmt_init($this->link);
                    $stmt->prepare("INSERT INTO `product`(`product_id`,`model`,`sku`,`upc`,`ean`,`jan`,`isbn`,`mpn`,`location`,`quantity`,`stock_status_id`,`image`,`manufacturer_id`,`shipping`,`price`,`points`,`tax_class_id`,`date_available`,`weight`,`weight_class_id`,`length`,`width`,`height`,`length_class_id`,`subtract`,`minimum`,`sort_order`,`status`,`date_added`,`date_modified`,`viewed`)
                    VALUES(NULL,'','','','','','','','',1,5,?,0,1,?,0,0,NOW(),0,1,0,0,0,1,0,1,1,?,NOW(),NOW(),0);");
                    
                    $status = !empty($cost);
                    
                    if(empty($cost))
                        $cost = 0;
                    
                    $stmt->bind_param('sdi',$nimg,$cost,$status);
                    $stmt->execute();
                    
                    $ocpid = mysqli_stmt_insert_id($stmt);
                    
                    $stmt->close();
                    
                    $stmt = mysqli_stmt_init($this->link);
                    $stmt->prepare("UPDATE `product` SET `model`=?,sku=? WHERE `product_id`=?");
                    $model = 'art-'.$ocpid;
                    $stmt->bind_param('ssi',$model,$model,$ocpid);
                    $stmt->execute();
                    $stmt->close();
                    
                    //добавляем записи для EN и RU в product_description
                    foreach(array(1,4) as $lang)
                    {
                        $stmt = mysqli_stmt_init($this->link);
                        $stmt->prepare("INSERT INTO `product_description`(`product_id`,`language_id`,`name`,`description`,`meta_description`,`meta_keyword`,`tag`) VALUES(?,?,?,?,'','','');");
                        $stmt->bind_param('iiss',$ocpid,$lang,$fullname,$desc);
                        $stmt->execute();
                        $stmt->close();
                    }
                    
                    //линк на категорию
                    
                    $stmt = mysqli_stmt_init($this->link);
                    $stmt->prepare("INSERT INTO `product_to_category`(`product_id`,`category_id`) VALUES(?,?);");
                    $stmt->bind_param('ii',$ocpid,$occid);
                    $stmt->execute();
                    $stmt->close();
                    
                    //линк на склад
                    $store_id = 0;
                    $stmt = mysqli_stmt_init($this->link);
                    $stmt->prepare("INSERT INTO `product_to_store`(`product_id`,`store_id`) VALUES(?,?);");
                    $stmt->bind_param('ii',$ocpid,$store_id);
                    $stmt->execute();
                    $stmt->close();
                }
            }else{
                //вставляем по полной - новый товар
                $stmt->close();
                
                $nimg = '';
                
                if(empty($img) || $noimg == true)
                {
                    $md5simgn = '';
                }else{
                    $imgsrc = file_get_contents($img);
                    $md5simgn = md5($img);
                    file_put_contents(DIR_IMAGE_ARO.$md5simgn,$imgsrc);
                    
                    $imginfo = getimagesize(DIR_IMAGE_ARO.$md5simgn);
                    
                    switch($imginfo[2])
                    {
                        case IMG_JPEG:
                        case IMG_JPG:

                            $nimg = $md5simgn.'.jpg';
                            rename(DIR_IMAGE_ARO.$md5simgn,DIR_IMAGE_ARO.$md5simgn.'.jpg');

                        break;

                        case IMG_PNG:

                            $nimg = $md5simgn.'.png';
                            rename(DIR_IMAGE_ARO.$md5simgn,DIR_IMAGE_ARO.$md5simgn.'.png');
                        break;

                        case IMG_GIF:

                            $nimg = $md5simgn.'.gif';
                            rename(DIR_IMAGE_ARO.$md5simgn,DIR_IMAGE_ARO.$md5simgn.'.gif');

                        break;

                        default:
                            unlink(DIR_IMAGE_ARO.$md5simgn);
                        break;
                    }
                    
                    //
                    $nimg = 'cache/grabber/aro/'.$nimg;
                }

                $costn = (empty($cost))?0:$cost;
                $status  = (empty($cost))?0:1;
                $md5n = md5(mb_strtoupper($fullname,'utf-8'));
                
                $stmt = mysqli_stmt_init($this->link);
                $stmt->prepare("INSERT INTO `grab_products`(`id`,`md5`,`md5simg`,`gcid1`,`gcid2`,`gcid3`,`cost1`,`costd1`,`cost2`,`costd2`,`cost3`,`costd3`) VALUES(NULL,?,?,0,0,?,0,0,0,0,?,0);");
                $stmt->bind_param('ssid',$md5n,$md5simgn,$cid,$costn);
                $stmt->execute();
                $stmt->close();
                
                //пошло добавление товара
                
                $stmt = mysqli_stmt_init($this->link);
                $stmt->prepare("INSERT INTO `product`(`product_id`,`model`,`sku`,`upc`,`ean`,`jan`,`isbn`,`mpn`,`location`,`quantity`,`stock_status_id`,`image`,`manufacturer_id`,`shipping`,`price`,`points`,`tax_class_id`,`date_available`,`weight`,`weight_class_id`,`length`,`width`,`height`,`length_class_id`,`subtract`,`minimum`,`sort_order`,`status`,`date_added`,`date_modified`,`viewed`)
                VALUES(NULL,'','','','','','','','',1,5,?,0,1,?,0,0,NOW(),0,1,0,0,0,1,0,1,1,?,NOW(),NOW(),0);");

                $stmt->bind_param('sdi',$nimg,$costn,$status);
                $stmt->execute();

                $ocpid = mysqli_stmt_insert_id($stmt);

                $stmt->close();
                
                $stmt = mysqli_stmt_init($this->link);
                $stmt->prepare("UPDATE `product` SET `model`=?,sku=? WHERE `product_id`=?");
                $model = 'art-'.$ocpid;
                $stmt->bind_param('ssi',$model,$model,$ocpid);
                $stmt->execute();
                $stmt->close();

                //добавляем записи для EN и RU в product_description
                foreach(array(1,4) as $lang)
                {
                    $stmt = mysqli_stmt_init($this->link);
                    $stmt->prepare("INSERT INTO `product_description`(`product_id`,`language_id`,`name`,`description`,`meta_description`,`meta_keyword`,`tag`) VALUES(?,?,?,?,'','','');");
                    $stmt->bind_param('iiss',$ocpid,$lang,$fullname,$desc);
                    $stmt->execute();
                    $stmt->close();
                }

                //линк на категорию

                $stmt = mysqli_stmt_init($this->link);
                $stmt->prepare("INSERT INTO `product_to_category`(`product_id`,`category_id`) VALUES(?,?);");
                $stmt->bind_param('ii',$ocpid,$occid);
                $stmt->execute();
                $stmt->close();

                //линк на склад
                $store_id = 0;
                $stmt = mysqli_stmt_init($this->link);
                $stmt->prepare("INSERT INTO `product_to_store`(`product_id`,`store_id`) VALUES(?,?);");
                $stmt->bind_param('ii',$ocpid,$store_id);
                $stmt->execute();
                $stmt->close();
            }
        }
        
        private function grabProduct($url,$cid,$occid,$spec,$noimg)
        {
            $tmp = file_get_contents($url);
            $tmp = iconv('windows-1251','utf-8',$tmp);
            $name = '';
            $namep3 = '';
            $img = '';
            $desc = '';
            
            if(preg_match('/<h1>([^<]+)<\/h1>/',$tmp,$arr))
            {
                $name = $arr[1];
            }
            
            $part = explode('<span style="color:#cd1384;">',$tmp);
            if($part > 3)
            {
                $part = explode('</span>',$part[2]);
                $part = $part[0];
                $md5 = md5($part);

                if($md5 != '980d44eef032fb042e5c7bd76ffcdd19' && $md5 != '08b32963db80467edf1e8017d817d91c' && $md5 != '6cdd5db762dd7664f9fc769ee39538b2')
                    die('fuck:'.__FILE__.',line:'.__LINE__.',sub:'.$part);
                
                $namep3 = $part;
            }

            $part = explode('<td style="padding: 0px 0px 10px 0px;">',$tmp);
            array_shift($part);
            $part = explode('<table class="head_product">',$part[0]);
            $part = $part[0];
            
            if(preg_match('/<img.+src="([^"]+)/',$part,$arr))
            {
                $img = 'http://www.aro-market.ru'.$arr[1];
            }
            
            $part = explode('</div>',$part);
            $part = array_pop($part);
            
            $part = strip_tags($part);
            $part = trim($part);
            
            $desc = $part;
            
            $part = explode('<table class="product ">',$tmp);
            array_shift($part);
            
            if(count($part) > 0)
            {
                $tmp = explode('</table>',$part[count($part)-1]);
                $part[count($part)-1] = $tmp[0];
            }

            foreach($part as $k=>$v)
            {
                $type = '';
                $namep2 = '';
                $fullname = '';
                $cost = '';
                
                $tmp = explode('<td',$v);
                
                if(preg_match('/>([^<]+)</',$tmp[1],$arr))
                {
                    $type = trim(strip_tags($arr[1]));
                }
                
                if(preg_match('/>([^<]+)</',$tmp[2],$arr))
                {
                    $namep2 = trim(strip_tags($arr[1]));
                }
                
                $fullname = $name . " ".$namep2;
                
                if(!empty($namep3))
                    $fullname .= ','.$namep3;
                
                if(preg_match('/>([^<]+)</',$tmp[4],$arr))
                {
                    $tmp2 = trim(strip_tags($arr[1]));
                    
                    if(preg_match('/([0-9]+)/',$tmp2,$arr2))
                    {
                        $cost = $arr2[1];
                    }
                }

                switch($spec)
                {
                    case 1://дезодорант
                    
                        if(preg_match('/дезодорант/iu',$type))
                        {
                            $this->processProduct($cid,$occid,$fullname,$cost,$noimg,$img,$desc);
                        }
                    
                    break;
                    
                    case 2://духи
                    
                        if(preg_match('/духи/iu',$type))
                        {
                            $this->processProduct($cid,$occid,$fullname,$cost,$noimg,$img,$desc);
                        }
                    
                    break;
                    
                    case 3://одеколон
                    
                        if(preg_match('/одеколон/iu',$type))
                        {
                            $this->processProduct($cid,$occid,$fullname,$cost,$noimg,$img,$desc);
                        }
                    
                    break;
                    
                    case 4://парфюмированная вода
                    
                        if(preg_match('/парфюмированная вода/iu',$type))
                        {
                            $this->processProduct($cid,$occid,$fullname,$cost,$noimg,$img,$desc);
                        }
                    
                    break;
                    
                    case 5://туалетная вода
                    
                        if(preg_match('/туалетная вода/iu',$type))
                        {
                            $this->processProduct($cid,$occid,$fullname,$cost,$noimg,$img,$desc);
                        }
                    
                    break;
                    
                    case 6://уход
                    
                        if(preg_match('/уход/iu',$type))
                        {
                            $this->processProduct($cid,$occid,$fullname,$cost,$noimg,$img,$desc);
                        }
                    
                    break;
                    
                    case 7://тестер
                    
                        if(preg_match('/тестер/iu',$type))
                        {
                            $this->processProduct($cid,$occid,$fullname,$cost,$noimg,$img,$desc);
                        }
                    
                    break;
                }
            }
        }
        
        private function grabProducts($url,$cid,$occid,$spec)
        {
            $tmp = file_get_contents('http://www.aro-market.ru'.$url);
            $tmp = explode('<table class="list_product">',$tmp);

            if(count($tmp) > 1)
            {
                array_shift($tmp);

                foreach($tmp as $k=>$v)
                {
                    $noimg = false;
                    $v = explode('<td class="img">',$v);
                    $v = explode('</td>',$v[1]);
                    $v = $v[0];
                    
                    if(preg_match('/src="([^"]+)/',$v,$arr))
                    {
                        if(preg_match('/no_image\.jpg/',$arr[1]))
                        {
                            $noimg = true;
                        }
                    }
                    
                    if(preg_match('/<a.+href="([^"]+)/',$v,$arr))
                    {
                        $this->grabProduct('http://www.aro-market.ru'.$arr[1],$cid,$occid,$spec,$noimg);
                    }
                }
            }
        }
        
        private function grabCatalog($cid,$occid)
        {
            $stmt = mysqli_stmt_init($this->link);
            var_dump('enter grabCatalog:',$cid,$occid);
            flush();
            $larr = array();
            
            $stmt->prepare("SELECT c.`id`,c.`oc_cid`,c.`url`,sp.`spec1` FROM `grab_categories` as c LEFT JOIN `grab_special` as sp on sp.`gid`=c.`id` WHERE c.`parent_id`=?");
            $stmt->bind_param('i',$cid);
            
            $stmt->execute();
            $stmt->store_result();
            
            if($stmt->num_rows() > 0)
            {
                $url = '';
                $id = $occid2 = $spec = 0;
                
                $stmt->bind_result($id,$occid2,$url,$spec);
                
                while($stmt->fetch())
                {
                    $larr[] = array('id'=>$id,'occid2'=>$occid2,'url'=>$url,'spec'=>$spec);
                }

                $stmt->free_result();
                $stmt->close();
                
                
                foreach($larr as $item)
                {
                    if(!empty($item['url']))
                    {
                        var_dump('try enter grabProducts:',$item['url'],$cid,$item['occid2'],$item['spec']);
                        flush();
                        $this->grabProducts($item['url'],$cid,$item['occid2'],$item['spec']);
                        var_dump('try leave grabProducts:',$item['url'],$cid,$item['occid2'],$item['spec']);
                        flush();
                    }
                    var_dump('try enter grabCatalog:',$item['id'],$item['occid2']);
                    flush();
                    $this->grabCatalog($item['id'],$item['occid2']);
                    var_dump('try leave grabCatalog:',$item['id'],$item['occid2']);
                    flush();
                }

            }else{
                $stmt->close();
            }
        }
        
        public function collectCategories()
        {
            $catid = $cid = 0;
            
            $cat = $this->cats['root'];
            
            $this->processCategory($cat['name'],0,$catid,'',0);
            $cid = $catid;
            
            foreach($this->cats['root']['ch'] as $k=>$v)
            {
                $this->processCategory($v['name'],$cid,$catid,str_replace('http://www.aro-market.ru','',$v['url']),$v['spec']);
                $this->processBrands($v['url'],$catid,$v['spec']);
            }
        }
        
        public function collectProducts()
        {
            $tag = self::TAG;
            
            $larr = array();
            
            $stmt = mysqli_stmt_init($this->link);
            
            $stmt->prepare("SELECT cs.`cid`,cs.`oc_cid` FROM `grab_cat_settings` as cs WHERE tag=?");
            $stmt->bind_param('i',$tag);
            
            $stmt->execute();
            
            $stmt->store_result();
            
            if($stmt->num_rows() > 0)
            {
                $cid = $occid = 0;
                
                $stmt->bind_result($cid,$occid);
                
                while($stmt->fetch())
                {
                    $larr[] = array('cid'=>$cid,'occid'=>$occid);
                    //$this->checkAndRebuildStruct($cid,$occid);
                    //$this->grabCatalog($cid,$occid);
                }
                
                $stmt->free_result();
                $stmt->close();
                
                foreach($larr as $item)
                {
                    //$this->checkAndRebuildStruct($item['cid'],$item['occid']);
                    $this->grabCatalog($item['cid'],$item['occid']);
                }
            }else{
                $stmt->close();
            }
        }
    }
    
?>