<?php


namespace app\app\controller;


use app\model\Chapter;
use app\model\User;
use app\model\UserBuy;
use Firebase\JWT\JWT;
use think\db\exception\DataNotFoundException;
use think\db\exception\ModelNotFoundException;
use think\facade\Db;

class Chapters extends Base
{
    protected $financeModel;
    protected function initialize()
    {
        parent::initialize(); // TODO: Change the autogenerated stub
        $this->financeModel = app('financeModel');
    }

    public function getList()
    {
        $book_id = input('book_id');

        $chapters = cache('chapters:' . $book_id);
        if (!$chapters) {
            $chapters = Chapter::where('book_id', '=', $book_id)->select();
            cache('chapters:' . $book_id, $chapters, null, 'redis');
        }

        $result = [
            'success' => 1,
            'chapters' => $chapters
        ];
        return json($result);
    }

    public function detail()
    {
        $id = input('id');
        $utoken = input('utoken');
        if (isset($utoken)) {
            $key = config('site.api_key');
            $info = JWT::decode($utoken, $key, array('HS256', 'HS384', 'HS512', 'RS256' ));
            $arr = (array)$info;
            $this->uid = $arr['uid'];
        }
        try {
            $chapter = cache('app:chapter:' . $id);
            if (!$chapter) {
                $chapter = Chapter::with('book')->findOrFail($id);
                $chapter['photos'] = Db::name('photo')->where('chapter_id','=',$chapter['id'])
                    ->order('pic_order','desc')
                    ->partition(['p0','p1','p2','p3','p4','p5','p6','p7','p8','p9','p10'])->select();
                foreach ($chapter['photos'] as &$photo) {
                    if (substr($photo['img_url'], 0, 4) === "http") {

                    } else {
                        $photo['img_url'] = $this->img_domain . $photo['img_url'];
                    }
                }
                if (substr($chapter->book->cover_url, 0, 4) === "http") {

                } else {
                    $chapter->book->cover_url = $this->img_domain . $chapter->book->cover_url;
                }
				cache('app:chapter:' . $id, $chapter, null, 'redis');
            }

            $flag = true;
            if ($chapter->book->start_pay >= 0) {
                if ($chapter->chapter_order >= $chapter->book->start_pay) { //如果本章序大于起始付费章节，则是付费章节
                    $flag = false;
                }
            } else { //如果是倒序付费设置
                $abs = abs($chapter->book->start_pay) - 1; //取得倒序的绝对值，比如-2，则是倒数第2章开始付费
                $max_chapter_order = cache('maxChapterOrder:' . $chapter->book_id);
                if (!$max_chapter_order) {
                    $max_chapter_order = Db::query("SELECT MAX(chapter_order) as max FROM " . $this->prefix . "chapter WHERE book_id=:id",
                        ['id' => $chapter->book_id])[0]['max'];
                    cache('maxChapterOrder:' . $chapter->book_id, $max_chapter_order);
                }
                $start_pay = (float)$max_chapter_order - $abs; //计算出起始付费章节
                if ($chapter->chapter_order >= $start_pay) { //如果本章序大于起始付费章节，则是付费章节
                    $flag = false;
                }
            }

            if($flag == false) { //如果需要付费，再判断用户是否登录
                if (empty($this->uid) || is_null($this->uid)) { //如果用户已经登录

                } else {
                    try {
                        $user = User::findOrFail($this->uid);
                        $time = intval($user->vip_expire_time) - time(); //计算vip用户时长
                        if ($time > 0) { //如果是vip会员且没过期，则可以不受限制
                            $flag = true;
                        } else { //如果不是会员，则判断用户是否购买本章节
                            $map[] = ['user_id', '=', $this->uid];
                            $map[] = ['chapter_id', '=', $id];
                            try {
                                $userBuy = UserBuy::where($map)->findOrFail(); //如果购买了
                                $flag = true;
                            } catch (ModelNotFoundException $e) { //如果没购买，则判断余额是否够，够则自动购买
                                $result = $this->financeModel->buyChapter($chapter, $this->uid);
                                if ((int)$result['success'] == 1) { //购买成功
                                    $flag = true;
                                }
                            }
                        }
                    } catch (ModelNotFoundException $e) {
                        return json(['success' => 0, 'msg' => '用户不存在']);
                    }

                }
            }

            $book_id = $chapter->book_id;
            $prev = cache('chapter_prev:' . $id);
            if (!$prev) {
                $prev = Db::query(
                    'select * from ' . $this->prefix . 'chapter where book_id=' . $book_id . ' and chapter_order<' . $chapter->chapter_order . ' order by id desc limit 1');
                cache('chapter_prev:' . $id, $prev, null, 'redis');
            }
            $next = cache('chapter_next:' . $id);
            if (!$next) {
                $next = Db::query(
                    'select * from ' . $this->prefix . 'chapter where book_id=' . $book_id . ' and chapter_order>' . $chapter->chapter_order . ' order by id limit 1');
                cache('chapter_next:' . $id, $next, null, 'redis');
            }

            if ($flag) {
                $result = [
                    'success' => 1,
                    'chapter' => $chapter,
                    'prev' => count($prev) > 0 ? $prev[0]['id'] : -1,
                    'next' => count($next) > 0 ? $next[0]['id'] : -1
                ];
            } else {
                $pic = Db::name('photo')->where('chapter_id','=',$chapter['id'])
                    ->partition(['p0','p1','p2','p3','p4','p5','p6','p7','p8','p9','p10'])->limit(1)->find();
                if (substr($pic->img_url, 0, 4) === "http") {

                } else {
                    $pic->img_url = $this->img_domain . $pic->img_url;
                }
                $result = [
                    'success' => 0,
                    'pic' => $pic,
                    'money' => $chapter->book->money,
                    'prev' => count($prev) > 0 ? $prev[0]['id'] : -1,
                    'next' => count($next) > 0 ? $next[0]['id'] : -1
                ];
            }
            return json($result);
        } catch (DataNotFoundException $e) {
            return json(['success' => 0, 'msg' => '章节id错误']);
        } catch (ModelNotFoundException $e) {
            return json(['success' => 0, 'msg' => '章节id错误']);
        }
    }
}