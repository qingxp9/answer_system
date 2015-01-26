<?php
//装载模板文件
include_once("wx_tpl.php");
include_once("base-class.php");


//新建sae数据库类
$mysql = new SaeMysql();

//新建Memcache类
$mc=memcache_init();

//获取微信发送数据
$postStr = $GLOBALS["HTTP_RAW_POST_DATA"];


//每次抽取几题
$question_nums=20;

  //返回回复数据
if (!empty($postStr)){
          
    	//解析数据
          $postObj = simplexml_load_string($postStr, 'SimpleXMLElement', LIBXML_NOCDATA);
    	//发送消息方ID
          $fromUsername = $postObj->FromUserName;
    	//接收消息方ID
          $toUsername = $postObj->ToUserName;
   	    //消息类型
          $form_MsgType = $postObj->MsgType;
    
    	//欢迎消息
          if($form_MsgType=="event")
          {
             //获取事件类型
            $form_Event = $postObj->Event;
            //订阅事件
            if($form_Event=="subscribe")
            {
                  //欢迎词
                $welcome_str="欢迎来到YYF答题系统\n\n\n已添加由\n“NEUQ ACM俱乐部”\n提供的\n《计算机基础》题库。\n===NEUQ ACM===\n每次问答系统将从题库中随机抽取".$question_nums."道题目，都为单选题，输入选项对应字母答题。\n\n准备好了吗？输入“go”进行答题:\n（答案已尽量修正，若还有错误，请谅解）";
                   //回复欢迎文字消息
                  $msgType = "text";
                  $resultStr = sprintf($textTpl, $fromUsername, $toUsername, time(), $msgType, $welcome_str);
               	  echo $resultStr;
                  exit;  
             }
          }
    
    	//用户文字回复进行绑定、查询等操作
         
        if($form_MsgType=="text")
        {
            //获取用户发送的文字内容并过滤
            $form_Content = trim($postObj->Content);
            $form_Content = string::un_script_code($form_Content);
            
            
	  	   //如果发送内容不是空白则执行相应操作
 		    if(!empty($form_Content))
            {
                
                
                //用户帮助提示
 	           if(strtolower($form_Content)=="help")
               {
                     //帮助词
                        $help_str="感谢使用微信答题系统\n\n每次问答系统将从题库中随机抽取".$question_nums."道题目，都为单选题，输入选项对应字母答题\n\n输入“exit”退出当前答题\n\n输入“best”查询最好成绩\n\n输入“history”查询历史答题记录\n\n准备好了吗？输入“go”进行答题：";
                       //回复文字消息
                      $msgType = "text";
                      $resultStr = sprintf($textTpl, $fromUsername, $toUsername, time(), $msgType, $help_str);
                      echo $resultStr;
                      exit;  
               }
                
                
  		  		//用户跳出操作
 	           if(strtolower($form_Content)=="exit")
               {
                    //清空memcache动作
                   $mc->delete($fromUsername."_question_data");
                   
                   //清空memcache数据
                   $mc->delete($fromUsername."_question_order");
                   
                   //回复操作提示
                  $msgType = "text";
                  $resultStr = sprintf($textTpl, $fromUsername, $toUsername, time(), $msgType, "你已经退出当前答题，寻求帮助请输入“help”，重新挑战请输入“go”！");
               	  echo $resultStr;
                  exit;  
               }
                //用户查询最好成绩
                if(strtolower($form_Content)=="best")
                {
                	$question_value=$mysql->getLine("select * from answer_tb where answer_user='$fromUsername' order by answer_time asc limit 0,1");
                    //回复消息
                      $msgType = "text";
                      $resultStr = sprintf($textTpl, $fromUsername, $toUsername, time(), $msgType, "你最好的成绩为：".$question_value["answer_time"]."秒\n\n完成时间为：".$question_value["create_time"]);
                      echo $resultStr;
                      exit;  
                
                }
                //用户查询历史成绩，最新的10次
                if(strtolower($form_Content)=="history")
                {
                	$question_list=$mysql->getData("select * from answer_tb where answer_user='$fromUsername' 
                    			order by create_time desc limit 0,10");
                    
                    $out_str="";
                    foreach($question_list as $key=>$value)
                    {
                    	$out_str.=($key+1).". 在".$value["create_time"]."完成答题，成绩为答错".$value["answer_error"]."次，用时".$value["answer_time"]."秒\n\n";
                    
                    }
                    //回复消息
                      $msgType = "text";
                      $resultStr = sprintf($textTpl, $fromUsername, $toUsername, time(), $msgType, $out_str);
                      echo $resultStr;
                      exit;  
                
                }
                
                //开始答题
                if(strtolower($form_Content)=="go")
                {
                    //从题库中随机抽取5道题目

                    $question_list=$mysql->getData("select *
                                from question_tb 
                                where question_id >=
                                (select floor(80+rand()*((select max(question_id) from question_tb)-(select min(question_id) from question_tb)) + 
                                (select min(question_id) from question_tb)))
                                order by question_id limit ".$question_nums);
                    
                    //将数组序列化存放到缓存，创建当前用户题库，时间设定10分钟
                    
                    $mc->set($fromUsername."_question_data", serialize($question_list), 0, 600);
                    
                    //设定答题状态和次序
                    $mc->set($fromUsername."_question_order", "step_1", 0, 600);
                    
                    //设定开始时间和错误次数
                    $mc->set($fromUsername."_question_answer", time()."||0", 0, 600);
                
                }
                 //从memcache获取用户当前的题库
                $question_data=unserialize($mc->get($fromUsername."_question_data"));
                //从memcache获取用户上一次数据
                $question_order=$mc->get($fromUsername."_question_order");
                //从memcache获取用户上一次错误记录
                $question_answer=$mc->get($fromUsername."_question_answer");
                //初始化变量
                $question_answer_tips="";
                
                //如果在答题中
                if($question_order)
                {
                    //获取当前答第几题
                    $now_order=explode("_",$question_order);
                    $now_order=intval($now_order[1])-1;
                    //监测答案是否正确，如果错误则提示，并记录错误次数
                    if($now_order>0)
                    {
                    	if($question_data[$now_order-1]["question_true"]!=strtoupper($form_Content))
                        {
                            
                            //增加错误次数
                            $question_answer_arr=explode("||",$question_answer);
                            $question_answer_arr[1]++;
                            $question_answer=$question_answer_arr[0]."||".$question_answer_arr[1];
                            
                            $mc->set($fromUsername."_question_answer", $question_answer, 0, 600);
                            
                            //输出错误提示
                            $msgType = "text";
                            $resultStr = sprintf($textTpl, $fromUsername, $toUsername, time(), $msgType, "YYF说你的答案是错误的，让你重选。\n\n累计错误次数：".$question_answer_arr[1]);
                            echo $resultStr;
                            exit;  
                        
                        }
                        else
                        {
                        
                            $question_answer_tips="恭喜你答对了！\n\n";
                        }
                    
                    }
                    //如果已经完成答题数目，给出结果
                    if($now_order>=$question_nums)
                    {
                        //答题成功！
                        $question_answer_arr=explode("||",$question_answer);
                        $answer_error=$question_answer_arr[1];
                        //计算答题时间
                        $answer_time=time()-$question_answer_arr[0];
                        
                        //获取用户回答问题的ID号
                        $answer_question=array();
                        foreach($question_data as $value)
                        {
                        	$answer_question[]=$value["question_id"];
                        
                        }
                        $answer_question=implode(",",$answer_question);
                        $nowtime=date("Y/m/d H:i:s",time());
                        
                        //保存到个人成绩
                        
                        $sql = "insert into answer_tb (answer_user,answer_question,answer_error,answer_time,create_time,status) 
                        		values ('$fromUsername','$answer_question','$answer_error','$answer_time','$nowtime',1)";
                        $mysql->runSql( $sql );
                        
                        
                        //清空memcache动作
                        $mc->delete($fromUsername."_question_data");
                        
                        //清空memcache数据
                        $mc->delete($fromUsername."_question_order");
                        
                        $msgType = "text";
                        $resultStr = sprintf($textTpl, $fromUsername, $toUsername, time(), $msgType, "您已经完成答题\n\n共耗时：".$answer_time."秒\n\n错误次数：".$answer_error."\n\n输入“best”查询最好成绩\n\n输入“history”查询历史答题记录\n\n输入“go”再来一次！"."\n\n如果你觉得系统还不错，关注下我的微博吧http://weibo.com/qingxp9");
                        echo $resultStr;
                        exit;  
                   
                    }
                    //提取题目信息
                    $question_out=$question_answer_tips."第".($now_order+1)."题：".$question_data[$now_order]["question_subject"]."\n\n".$question_data[$now_order]["question_options"];
                    
                    //增加题目计数
                    $mc->set($fromUsername."_question_order", "step_".($now_order+2), 0, 600);
                    
                    //输出题目
                  	$msgType = "text";
                  	$resultStr = sprintf($textTpl, $fromUsername, $toUsername, time(), $msgType, $question_out);
               	  	echo $resultStr;
                  	exit;  
                
                }
                 
                   //用户自动回复
                  $msgType = "text";
                  $resultStr = sprintf($textTpl, $fromUsername, $toUsername, time(), $msgType,  "无法识别你的指令，需求帮助请输入help");
               	  echo $resultStr;
                  exit;  
                
             
            
            }
    
        }
    
    
  }
  else 
  {
          echo "";
          exit;
  }

?>