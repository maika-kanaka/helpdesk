<?php

namespace App\Http\Controllers\Api\Trx;

use Validator;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

use \Firebase\JWT\JWT;


use App\Libraries\Auth;
use App\Models\Master\Category;
use App\Models\Sys\User;
use App\Models\Sys\UserSupport;
use App\Models\Trx\Ticket;

class TicketController extends Controller
{

    public function data(Request $request)
    {
        # param
        $jwt = trim($request->input('jwt'));
        $ticket_id = $request->get('ticket_id');

        # authentification
        $data_jwt = JWT::decode($jwt, config('jwt.key'), config('jwt.algo'));

        # query
        $query = Ticket::table('t_ticket')
                        ->select(\DB::raw('t_ticket.*, t_cat.category_name, u_created.user_fullname, u_accepted.user_fullname as user_acc_fullname, u_rejected.user_fullname AS user_reject_fullname'))
                        ->leftJoin(Category::$table . " as t_cat", "t_cat.category_id", "=", "t_ticket.category_id")
                        ->leftJoin(User::$table . " as u_created", "u_created.user_id", "=", "t_ticket.created_by")
                        ->leftJoin(User::$table . " as u_accepted", "u_accepted.user_id", "=", "t_ticket.accepted_by")
                        ->leftJoin(User::$table . " as u_rejected", "u_rejected.user_id", "=", "t_ticket.rejected_by")
                        ->orderBy('t_ticket.created_at', 'desc');

        # ambil data sesuai kategori teknisi
        if( !empty($data_jwt->user->user_category) )
        {
            $query->where('t_ticket.category_id', $data_jwt->user->user_category);
        }

        # filter ambil data berdasarkan hak akses
        if (Auth::role_api($data_jwt->user->group_id, 'ticket_view_all') === False) {
            if (Auth::role_api($data_jwt->user->group_id, 'ticket_view_support')) {
                # ambil data sesuai teknisi
                $user_support = UserSupport::table()->where('user_id', $data_jwt->user->user_id)->get();

                if (!empty($user_support)) {
                    $user_support_id = [];
                    foreach ($user_support as $kus => $vus) {
                        $user_support_id[] = $vus->support_id;
                    }
                    $query->whereIn('t_ticket.support_id', $user_support_id);
                }
            } else {
                # ambil data inputan sendiri
                $query->where('t_ticket.created_by', $data_jwt->user->user_id);
            }
        }

        # filter view detail
        if(!empty($ticket_id)){
            $query->where('ticket_id', $ticket_id);
        }

        $tickets = $query->get();

        return response()->json([
            'tickets' => $tickets,
            'status' => True
        ]);
    }

    public function save(Request $request)
    {
        # param
        $jwt = trim($request->input('jwt'));

        # hak akses
        try {
            $data_jwt = JWT::decode($jwt, config('jwt.key'), config('jwt.algo'));
        } catch (\Exception $e) {
            $data_jwt = false;
        }

        if($data_jwt === false){
            return response()->json(array('is_valid' => false, 'message' => 'Token invalid!'));
        }

        # ambil input
        $input = $this->_getInput([
            'event' => 'add',
            'data_jwt' => $data_jwt,
            'request' => $request
        ]);

        # simpan
        Ticket::table()->insert($input['input']);
        if(!empty($input['image'])){
            \File::put(public_path(). '/imgs/ticket/' . $input['input']['ticket_photo'], base64_decode($input['image']));
        }

        # msg
        return response()->json([
            'success' => True
        ]);
    }

    private function _getInput($config = [])
    {
        # alias
        $req = $config['request'];

        if($config['event'] == 'add'){
            $input['ticket_id'] = Ticket::primaryKey();
            $input['ticket_code'] = Ticket::generateCode();
            $input['ticket_status'] = 'open';
        }

        $input['support_id'] = 1; // default IT
        $input['category_id'] = trim($req->input('category'));
        $input['ticket_title'] = trim($req->input('title'));
        $input['ticket_priority'] = $req->input('priority') == 'true' ? 'high' : 'low';

        $image = $req->input('photo-file');
        if(!empty($image) && $image != 'undefined')
        {
            $image = str_replace(['data:image/png;base64,', 'data:image/jpeg;base64,', 'data:image/jpg;base64,'], '', $image);
            $image = str_replace(' ', '+', $image);
            $imageName = $input['ticket_id'] .'.'. 'jpg';
            $input['ticket_photo'] = $imageName;
        }
        $input['ticket_desc'] = trim($req->input('description'));

        $input['created_at'] = date('Y-m-d H:i:s');
        $input['created_by'] = $config['data_jwt']->user->user_id;

        return ['input' => $input, 'image' => $image];
    }


    public function status_change(Request $request)
    {
        # param
        $ticket_id = trim($request->input('ticket_id'));
        $status = trim($request->input('status'));
        $jwt = trim($request->input('jwt'));

        # hak akses
        try {
            $data_jwt = JWT::decode($jwt, config('jwt.key'), config('jwt.algo'));
        } catch (\Exception $e) {
            $data_jwt = false;
        }

        if($data_jwt === false){
            return response()->json(array('is_valid' => false, 'message' => 'Token invalid!'));
        }

        # data
        $data_ticket = Ticket::table()->where('ticket_id', $ticket_id)->first();

        # kondisi update
        $update = [];
        if($status == 'accept' && $data_ticket->ticket_status == 'open'){
            $update = [
                'ticket_status' => 'accepted',
                'accepted_at' => date('Y-m-d H:i:s'),
                'accepted_by' => $data_jwt->user->user_id
            ];
        }else if($status == 'reject' && $data_ticket->ticket_status == 'open'){
            $update = [
                'ticket_status' => 'rejected',
                'rejected_at' => date('Y-m-d H:i:s'),
                'rejected_by' => $data_jwt->user->user_id,
                'rejected_notes' => trim(htmlentities($request->input('reason')))
            ];
        }else if($status == 'canceled' && $data_ticket->ticket_status == 'open'){
            $update = [
                'ticket_status' => 'canceled',
                'updated_at' => date('Y-m-d H:i:s'),
                'updated_by' => $data_jwt->user->user_id
            ];
        }

        # updating
        if(!empty($update)){
            Ticket::table()->where('ticket_id', $ticket_id)->update($update);
        }

        # message
        return response()->json(['status' => true]);
    }

    public function delete(Request $request)
    {
    	try {
            $ticket_id = trim($request->input('ticket_id'));
            $jwt = trim($request->input('jwt'));
            
            try {
                $data_jwt = JWT::decode($jwt, config('jwt.key'), config('jwt.algo'));
            } catch (\Exception $e) {
                $data_jwt = false;
            }
    
            if($data_jwt === false){
                return response()->json(array('is_valid' => false, 'message' => 'Token invalid!'));
            }
    
            $data_ticket = Ticket::table()->where('ticket_id', $ticket_id);
            $data_ticket->delete();


            return response()->json(['success'=>True, 'message' => 'Data Deleted Successfuly'], 200);

        } catch (\Exception $e) {
            return response()->json(['success'=>False, 'message' => $e->getMessage()], 400);
        }
    }

    public function edit(Request $request)
    {
        # param
        $jwt = trim($request->input('jwt'));
        $ticket_id = trim($request->input('ticket_id'));
        // $image = $request->input('ticket_photo');
        // if(!empty($image) && $image != 'undefined')
        // {
        //     $image = str_replace(['data:image/png;base64,', 'data:image/jpeg;base64,', 'data:image/jpg;base64,'], '', $image);
        //     $image = str_replace(' ', '+', $image);
        //     $imageName = $request['ticket_id'] .'.'. 'jpg';
        //     $update['ticket_photo'] = $imageName;
        // }
        
        # hak akses
        try {
            $data_jwt = JWT::decode($jwt, config('jwt.key'), config('jwt.algo'));
        } catch (\Exception $e) {
            $data_jwt = false;
        }

        if($data_jwt === false){
            return response()->json(array('is_valid' => false, 'message' => 'Token invalid!'));
        }

        $data_ticket = Ticket::table()->where('ticket_id', $ticket_id)->first();
      
        # kondisi update
        $update = [];
        if($ticket_id == $data_ticket->ticket_id ){
           
            $update = [
                'ticket_title' => trim($request->input('ticket_title')),
                'category_id' => trim($request->input('category_id')),
                'ticket_priority' => $request->input('ticket_priority') == 'true' ? 'high' : 'low',
                'ticket_desc' => trim($request->input('ticket_desc')),
                // 'ticket_photo' => $image,
                'updated_at' => date('Y-m-d H:i:s'),
                'updated_by' => $data_jwt->user->user_id
            ];
        }

        # updating
        if(!empty($update)){
            Ticket::table()->where('ticket_id', $ticket_id)->update($update);
        }

        # message
        return response()->json(['status' => true]);

    }
    
    public function create_report()
    {
        $request = app('request');

        $data['tickets'] = Ticket::table('t')
                                    ->leftJoin(Category::$table . " AS c", "c.category_id", "=", "t.category_id")
                                    ->select("t.*", "c.category_name")
                                    ->orderBy('created_at');

        if( !empty($request->input('date_from')) && $request->input('date_from') != 'undefined' ){
            $date_from = new \DateTIme($request->input('date_from'));
            if($date_from !== false){
                $data['tickets']->where('t.created_at', '>=', $date_from->format('Y-m-d'));
            }
            \Log::info('date_from:'. $date_from->format('Y-m-d'));
        }

        if( !empty($request->input('date_to')) && $request->input('date_to') != 'undefined' ){
            $date_to = new \DateTIme($request->input('date_to'));
            if($date_to !== false){
                $data['tickets']->where('t.created_at', '<=', $date_to->format('Y-m-d'));
            }
            \Log::info('date_to:'. $date_to->format('Y-m-d'));
        }

        if( !empty($request->input('category')) ){
            if($request->input('category') != 'undefined'){
                $data['tickets']->where('t.category_id', '=', $request->input('category'));
            }
        }

        $data['tickets'] = $data['tickets']->get();

        return view('rpt/ticket', $data);
    }

}

