<?php

namespace App\Http\Controllers\Assets;

use App\Events\CheckoutableCheckedIn;
use App\Models\Actionlog;
use App\Helpers\Helper;
use App\Http\Controllers\CheckInOutRequest;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\CheckoutAcceptance;
use App\Models\Setting;
use App\View\Label;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use App\Http\Requests\AssetCheckoutRequest;
use App\Models\CustomField;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

class BulkAssetsController extends Controller
{
    use CheckInOutRequest;

    /**
     * Display the bulk edit page.
     *
     * @return View
     * @throws \Illuminate\Auth\Access\AuthorizationException
     * @internal param int $assetId
     * @since [v2.0]
     * @author [A. Gianotto] [<snipe@snipe.net>]
     */
    public function edit(Request $request)
    {
        $this->authorize('view', Asset::class);

        if (! $request->filled('ids')) {
            return redirect()->back()->with('error', trans('admin/hardware/message.update.no_assets_selected'));
        }

        // Figure out where we need to send the user after the update is complete, and store that in the session
        $bulk_back_url = request()->headers->get('referer');
        session(['bulk_back_url' => $bulk_back_url]);

        $asset_ids = array_values(array_unique($request->input('ids')));

        //custom fields logic
        $asset_custom_field = Asset::with(['model.fieldset.fields', 'model'])->whereIn('id', $asset_ids)->whereHas('model', function ($query) {
            return $query->where('fieldset_id', '!=', null);
        })->get();

        $models = $asset_custom_field->unique('model_id');
        $modelNames = [];
        foreach ($models as $model) {
            $modelNames[] = $model->model->name;
        }
        if ($request->filled('bulk_actions')) {
            switch ($request->input('bulk_actions')) {
                case 'labels':
                    $this->authorize('view', Asset::class);
                    $assets_found = Asset::find($asset_ids);
                    
                    if ($assets_found->isEmpty()){
                        return redirect()->back();
                    }

                    return (new Label)
                        ->with('assets', $assets_found)
                        ->with('settings', Setting::getSettings())
                        ->with('bulkedit', true)
                        ->with('count', 0);

                case 'delete':
                    $this->authorize('delete', Asset::class);
                    $assets = Asset::with('assignedTo', 'location')->find($asset_ids);
                    $assets->each(function ($asset) {
                        $this->authorize('delete', $asset);
                    });

                    return view('hardware/bulk-delete')->with('assets', $assets);

                case 'restore':
                    $this->authorize('update', Asset::class);
                    $assets = Asset::withTrashed()->find($asset_ids);
                    $assets->each(function ($asset) {
                        $this->authorize('delete', $asset);
                    });

                    return view('hardware/bulk-restore')->with('assets', $assets);

                case 'edit':
                    $this->authorize('update', Asset::class);
                    return view('hardware/bulk')
                        ->with('assets', $asset_ids)
                        ->with('statuslabel_list', Helper::statusLabelList())
                        ->with('models', $models->pluck(['model']))
                        ->with('modelNames', $modelNames);

                case 'checkin':
                    $this->authorize('checkin', Asset::class);
                    $assets = Asset::with('assignedTo')->find($asset_ids);
                    $user = Auth::user();
                    $assets->each(function ($asset) {
                        $this->authorize('checkin', $asset);
                    });

                    return view('hardware.bulk-checkin')
                        ->with('assets', $assets)
                        ->with('user', $user)
                        ->with('statusLabel_list', Helper::statusLabelList())
                        ->with('models', $models->pluck(['model']));
            }
        }

        return redirect()->back()->with('error', 'No action selected');
    }

    /**
     * Save bulk edits
     *
     * @return Redirect
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @internal param array $assets
     * @since [v2.0]
     */
    public function update(Request $request)
    {
        $this->authorize('update', Asset::class);
        $error_bag = [];

        // Get the back url from the session and then destroy the session
        $bulk_back_url = route('hardware.index');
        if ($request->session()->has('bulk_back_url')) {
            $bulk_back_url = $request->session()->pull('bulk_back_url');
        }

        $custom_field_columns = CustomField::all()->pluck('db_column')->toArray();

        if(Session::exists('ids')) {
            $assets = Session::get('ids');
        } elseif (! $request->filled('ids') || count($request->input('ids')) <= 0) {
            return redirect($bulk_back_url)->with('error', trans('admin/hardware/message.update.no_assets_selected'));
        }

        $assets = array_keys($request->input('ids'));

        if ($request->anyFilled($custom_field_columns)) {
            $custom_fields_present = true;
        } else {
            $custom_fields_present = false;
        }
        if (($request->filled('purchase_date'))
            || ($request->filled('expected_checkin'))
            || ($request->filled('purchase_cost'))
            || ($request->filled('supplier_id'))
            || ($request->filled('order_number'))
            || ($request->filled('warranty_months'))
            || ($request->filled('rtd_location_id'))
            || ($request->filled('requestable'))
            || ($request->filled('company_id'))
            || ($request->filled('status_id'))
            || ($request->filled('model_id'))
            || ($request->filled('next_audit_date'))
            || ($request->filled('null_purchase_date'))
            || ($request->filled('null_expected_checkin_date'))
            || ($request->filled('null_next_audit_date'))
            || ($request->anyFilled($custom_field_columns))

        ) {
            foreach ($assets as $assetId) {

                $this->update_array = [];

                $this->conditionallyAddItem('purchase_date')
                    ->conditionallyAddItem('expected_checkin')
                    ->conditionallyAddItem('model_id')
                    ->conditionallyAddItem('order_number')
                    ->conditionallyAddItem('requestable')
                    ->conditionallyAddItem('status_id')
                    ->conditionallyAddItem('supplier_id')
                    ->conditionallyAddItem('warranty_months')
                    ->conditionallyAddItem('next_audit_date');
                foreach ($custom_field_columns as $key => $custom_field_column) {
                    $this->conditionallyAddItem($custom_field_column);
                }

                if ($request->input('null_purchase_date')=='1') {
                    $this->update_array['purchase_date'] = null;
                }

                if ($request->input('null_expected_checkin_date')=='1') {
                    $this->update_array['expected_checkin'] = null;
                }

                if ($request->input('null_next_audit_date')=='1') {
                    $this->update_array['next_audit_date'] = null;
                }

                if ($request->filled('purchase_cost')) {
                    $this->update_array['purchase_cost'] =  $request->input('purchase_cost');
                }

                if ($request->filled('company_id')) {
                    $this->update_array['company_id'] = $request->input('company_id');
                    if ($request->input('company_id') == 'clear') {
                        $this->update_array['company_id'] = null;
                    }
                }

                if ($request->filled('rtd_location_id')) {
                    $this->update_array['rtd_location_id'] = $request->input('rtd_location_id');
                    if (($request->filled('update_real_loc')) && (($request->input('update_real_loc')) == '1')) {
                        $this->update_array['location_id'] = $request->input('rtd_location_id');
                    }
                }

                $changed = [];
                $assetCollection = Asset::where('id',$assetId)->get();

                foreach ($this->update_array as $key => $value) {
                    if ($this->update_array[$key] != $assetCollection->toArray()[0][$key]) {
                        $changed[$key]['old'] = $assetCollection->toArray()[0][$key];
                        $changed[$key]['new'] = $this->update_array[$key];
                    }
                }

                $logAction = new Actionlog();
                $logAction->item_type = Asset::class;
                $logAction->item_id = $assetId;
                $logAction->created_at = date("Y-m-d H:i:s");
                $logAction->user_id = Auth::id();
                $logAction->log_meta = json_encode($changed);
                $logAction->logaction('update');

                if($custom_fields_present) {
                    $asset = Asset::find($assetId);
                    $assetCustomFields = $asset->model()->first()->fieldset;
                    if($assetCustomFields && $assetCustomFields->fields) {
                        foreach ($assetCustomFields->fields as $field) {
                            if (array_key_exists($field->db_column, $this->update_array)) {
                                $asset->{$field->db_column} = $this->update_array[$field->db_column];
                                $saved = $asset->save();
                                if(!$saved) {
                                    $error_bag[] = $asset->getErrors();
                                }
                                continue;
                            } else {
                                $array = $this->update_array;
                                array_except($array, $field->db_column);
                                $asset->save($array);
                            }
                            if (!$asset->save()) {
                                $error_bag[] = $asset->getErrors();
                            }
                        }
                    }
                } else {
                    Asset::find($assetId)->update($this->update_array);
                }
            }
            if(!empty($error_bag)) {
                $errors = [];
                //find the customfield name from the name of the messagebag items
                foreach ($error_bag as $key => $bag) {
                    foreach($bag->keys() as $key => $value) {
                        CustomField::where('db_column', $value)->get()->map(function($item) use (&$errors) {
                            $errors[] = $item->name;
                        });
                    }
                }
                return redirect($bulk_back_url)->with('bulk_errors', array_unique($errors));
            }
            return redirect($bulk_back_url)->with('success', trans('admin/hardware/message.update.success'));
        }
        // no values given, nothing to update
        return redirect($bulk_back_url)->with('warning', trans('admin/hardware/message.update.nothing_updated'));
    }

    /**
     * Array to store update data per item
     * @var array
     */
    private $update_array;

    /**
     * Adds parameter to update array for an item if it exists in request
     * @param string $field field name
     * @return BulkAssetsController Model for Chaining
     */
    protected function conditionallyAddItem($field)
    {
        if (request()->filled($field)) {
            $this->update_array[$field] = request()->input($field);
        }

        return $this;
    }

    /**
     * Save bulk deleted.
     *
     * @param Request $request
     * @return View
     * @throws \Illuminate\Auth\Access\AuthorizationException
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @internal param array $assets
     * @since [v2.0]
     */
    public function destroy(Request $request)
    {
        $this->authorize('delete', Asset::class);

        $bulk_back_url = route('hardware.index');
        if ($request->session()->has('bulk_back_url')) {
            $bulk_back_url = $request->session()->pull('bulk_back_url');
        }

        if ($request->filled('ids')) {
            $assets = Asset::find($request->get('ids'));
            foreach ($assets as $asset) {
                $update_array['deleted_at'] = date('Y-m-d H:i:s');
                $update_array['assigned_to'] = null;

                DB::table('assets')
                    ->where('id', $asset->id)
                    ->update($update_array);
            } // endforeach

            return redirect($bulk_back_url)->with('success', trans('admin/hardware/message.delete.success'));
            // no values given, nothing to update
        }

        return redirect($bulk_back_url)->with('error', trans('admin/hardware/message.delete.nothing_updated'));
    }

    /**
     * Show Bulk Checkout Page
     * @return View View to checkout multiple assets
     */
    public function showCheckout()
    {
        $this->authorize('checkout', Asset::class);
        // Filter out assets that are not deployable.

        return view('hardware/bulk-checkout');
    }

    /**
     * Process Multiple Checkout Request
     * @return View
     */

     public function storeCheckout(AssetCheckoutRequest $request)
    {
        $this->authorize('checkout', Asset::class);

        try {
            $admin = Auth::user();

            $target = $this->determineCheckoutTarget();

            if (! is_array($request->get('selected_assets'))&& !$request->get('bulk_serial_assets') && !$request->get('bulk_assettag_assets')) {
                return redirect()->route('hardware.bulkcheckout.show')->withInput()->with('error', trans('admin/hardware/message.checkout.no_assets_selected'));
            }

            if ($request->get('selected_assets') && $request->get('bulk_serial_assets')) {
                return redirect()->route('hardware.bulkcheckout.show')->withInput()->with('error', 'Please choose only option. Assets field or bulk serial assets fields');
            }
            
            $asset_ids = [];

            if ($request->get('selected_assets')) {
                $asset_ids = array_filter($request->get('selected_assets'));

            }

            if ($request->get('bulk_serial_assets')) {
                $errorSerial = [];
                $bulkSerialAssets = nl2br($request->get('bulk_serial_assets'));
                $serialAssets = explode('<br />', $bulkSerialAssets);
                foreach ($serialAssets as $key => $serialAsset) {
                    $asset = Asset::where('serial', trim($serialAsset))->first();
                    if (!$asset || $asset->assigned_to || $asset->status_id != 2) {
                        array_push($errorSerial, $serialAsset);
                    } else {
                        array_push($asset_ids, $asset->id);
                    }
                }

                if (count($errorSerial)) {
                    $errorSerialStr = implode(',', $errorSerial);
                    return redirect()->back()->with('error', "Here's is the error serial list, please check them again ".$errorSerialStr);
                }
            }


            if ($request->get('bulk_assettag_assets')) {
                $errorTags = [];
                $bulkAssetTags = $request->get('bulk_assettag_assets');
                $assetTags = explode("\n", $bulkAssetTags);
                foreach ($assetTags as $tag) {
                    $asset = Asset::where('asset_tag', trim($tag))->first();
                    if (!$asset || $asset->assigned_to || $asset->status_id != 2) {
                        array_push($errorTags, $tag);
                    } else {
                        array_push($asset_ids, $asset->id);
                    }
                }
            
                if (count($errorTags)) {
                    $errorTagsStr = implode(',', $errorTags);
                    return redirect()->back()->with('error', "Here's is the error asset tag list, please check them again ".$errorTagsStr);
                }
            }


  

            if (request('checkout_to_type') == 'asset') {
                foreach ($asset_ids as $asset_id) {
                    if ($target->id == $asset_id) {
                        return redirect()->back()->with('error', 'You cannot check an asset out to itself.');
                    }
                }
            }

            $settings = \App\Models\Setting::getSettings();
    
            if ($settings->full_multiple_companies_support){
                foreach ($asset_ids as $asset_id) {
                    $asset = Asset::findOrFail($asset_id);
                    if ($target->company_id != $asset->company_id){
                        return redirect()->back()->with('error', 'One of the selected assets for checkout does not belong to the same company as the person or location it is being transferred to.');
                        
                    }
                }
            }

            $checkout_at = date('Y-m-d H:i:s');
            if (($request->filled('checkout_at')) && ($request->get('checkout_at') != date('Y-m-d'))) {
                $checkout_at = e($request->get('checkout_at'));
            }

            $expected_checkin = '';

            if ($request->filled('expected_checkin')) {
                $expected_checkin = e($request->get('expected_checkin'));
            }

            $errors = [];
            DB::transaction(function () use ($target, $admin, $checkout_at, $expected_checkin, $errors, $asset_ids, $request) {
                foreach ($asset_ids as $asset_id) {
                    $asset = Asset::findOrFail($asset_id);
                    $this->authorize('checkout', $asset);

                    $error = $asset->checkOut($target, $admin, $checkout_at, $expected_checkin, e($request->get('note')), $asset->name, null);

                    if ($target->location_id != '') {
                        $asset->location_id = $target->location_id;
                        $asset->unsetEventDispatcher();
                        $asset->save();
                    }

                    if ($error) {
                        array_merge_recursive($errors, $asset->getErrors()->toArray());
                    }
                }
            });

            if (! $errors) {
                // Redirect to the new asset page
                return redirect()->to('hardware')->with('success', trans('admin/hardware/message.checkout.success'));
            }
            // Redirect to the asset management page with error
            return redirect()->route('hardware.bulkcheckout.show')->with('error', trans('admin/hardware/message.checkout.error'))->withErrors($errors);
        } catch (ModelNotFoundException $e) {
            return redirect()->route('hardware.bulkcheckout.show')->with('error', $e->getErrors());
        }
    }



    public function restore(Request $request) {
        $this->authorize('update', Asset::class);
        $assetIds = $request->get('ids');
        if (empty($assetIds)) {
            return redirect()->route('hardware.index')->with('error', trans('admin/hardware/message.restore.nothing_updated'));
        } else {
            foreach ($assetIds as $key => $assetId) {
                $asset = Asset::withTrashed()->find($assetId);
                $asset->restore();
            }
            return redirect()->route('hardware.index')->with('success', trans('admin/hardware/message.restore.success'));
        }
    }

    public function bulkCheckin(Request $request, $backto = null)
    {
        $assetIds = $request->get('ids');
        $count = count($assetIds);
        $user = '';

        foreach($assetIds as $assetId) {
            // Check if the asset exists
            if (is_null($asset = Asset::find($assetId))) {
                // Redirect to the asset management page with error
                return redirect()->route('hardware.index')->with('error', trans('admin/hardware/message.does_not_exist'));
            }

            if (is_null($target = $asset->assignedTo)) {
                return redirect()->route('hardware.index')->with('error', trans('admin/hardware/message.checkin.already_checked_in'));
            }
            $this->authorize('checkin', $asset);

            if ($asset->assignedType() == Asset::USER) {
                $user = $asset->assignedTo;
            
            }
            $asset->expected_checkin = null;
            $asset->last_checkout = null;
            $asset->assigned_to = null;
            $asset->assignedTo()->disassociate($asset);
            $asset->assigned_type = null;
            $asset->accepted = null;
            $asset->name = $request->get('name');
            if ($request->filled('status_id')) {
                $asset->status_id = e($request->get('status_id'));
            }
            else
                $asset->status_id = 2;

            // This is just meant to correct legacy issues where some user data would have 0
            // as a location ID, which isn't valid. Later versions of Snipe-IT have stricter validation
            // rules, so it's necessary to fix this for long-time users. It's kinda gross, but will help
            // people (and their data) in the long run

            if ($asset->rtd_location_id == '0') {
                Log::debug('Manually override the RTD location IDs');
                Log::debug('Original RTD Location ID: ' . $asset->rtd_location_id);
                $asset->rtd_location_id = '';
                Log::debug('New RTD Location ID: ' . $asset->rtd_location_id);
            }

            if ($asset->location_id == '0') {
                Log::debug('Manually override the location IDs');
                Log::debug('Original Location ID: ' . $asset->location_id);
                $asset->location_id = '';
                Log::debug('New Location ID: ' . $asset->location_id);
            }

            $asset->location_id = $asset->rtd_location_id;

            if ($request->filled('location_id')) {
                Log::debug('NEW Location ID: ' . $request->get('location_id'));
                $asset->location_id = $request->get('location_id');

                if ($request->get('update_default_location') == 0) {
                    $asset->rtd_location_id = $request->get('location_id');
                }
            }
            $checkin_at = date('Y-m-d H:i:s');
            if (($request->filled('checkin_at')) && ($request->get('checkin_at') != date('Y-m-d'))) {
                $checkin_at = $request->get('checkin_at');
            }

            if (!empty($asset->licenseseats->all())) {
                foreach ($asset->licenseseats as $seat) {
                    $seat->assigned_to = null;
                    $seat->save();
                }

            }
            // Get all pending Acceptances for this asset and delete them
            $acceptances = CheckoutAcceptance::pending()->whereHasMorph('checkoutable',
                [Asset::class],
                function (Builder $query) use ($asset) {
                    $query->where('id', $asset->id);
                })->get();
            $acceptances->map(function ($acceptance) {
                $acceptance->delete();
            });

            // Was the asset updated?
            if ($asset->save()) {
                event(new CheckoutableCheckedIn($asset, $target, Auth::user(), $request->input('note'), $checkin_at));
            }
        }
        if ((isset($user))) {
            return redirect()->route('users.show', $user->id)->with('success', trans('admin/hardware/message.checkin.multi_success', ['count' => $count ]));
        }
    }
}