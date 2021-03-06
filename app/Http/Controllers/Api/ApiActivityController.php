<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use App\Models\Contact\Contact;
use App\Models\Account\Activity;
use App\Models\Account\ActivityType;
use App\Models\Journal\JournalEntry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Http\Resources\Activity\Activity as ActivityResource;

class ApiActivityController extends ApiController
{
    /**
     * Get the list of activities.
     *
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection|\Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        try {
            $activities = auth()->user()->account->activities()
                ->orderBy($this->sort, $this->sortDirection)
                ->paginate($this->getLimitPerPage());
        } catch (QueryException $e) {
            return $this->respondInvalidQuery();
        }

        return ActivityResource::collection($activities)->additional(['meta' => [
            'statistics' => auth()->user()->account->getYearlyActivitiesStatistics(),
        ]]);
    }

    /**
     * Get the detail of a given activity.
     *
     * @param Request $request
     *
     * @return ActivityResource|\Illuminate\Http\JsonResponse
     */
    public function show(Request $request, $activityId)
    {
        try {
            $activity = Activity::where('account_id', auth()->user()->account_id)
                ->findOrFail($activityId);
        } catch (ModelNotFoundException $e) {
            return $this->respondNotFound();
        }

        return new ActivityResource($activity);
    }

    /**
     * Store the activity.
     *
     * @param Request $request
     *
     * @return ActivityResource|\Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $isvalid = $this->validateUpdate($request);
        if ($isvalid !== true) {
            return $isvalid;
        }

        try {
            $activity = Activity::create(
                $request->only([
                    'summary',
                    'date_it_happened',
                    'activity_type_id',
                    'description',
                ])
                + ['account_id' => auth()->user()->account_id]
            );
        } catch (QueryException $e) {
            return $this->respondNotTheRightParameters();
        }

        // Log a journal entry
        JournalEntry::add($activity);

        // Now we associate the activity with each one of the attendees
        $attendeesID = $request->get('contacts');
        foreach ($attendeesID as $attendeeID) {
            $contact = Contact::where('account_id', auth()->user()->account_id)
                ->findOrFail($attendeeID);
            $contact->activities()->attach($activity, [
                    'account_id' => auth()->user()->account_id,
                ]);
            $contact->calculateActivitiesStatistics();
        }

        return new ActivityResource($activity);
    }

    /**
     * Update the activity.
     *
     * @param Request $request
     * @param int $activityId
     *
     * @return ActivityResource|\Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $activityId)
    {
        try {
            /** @var Activity */
            $activity = Activity::where('account_id', auth()->user()->account_id)
                ->findOrFail($activityId);
        } catch (ModelNotFoundException $e) {
            return $this->respondNotFound();
        }

        $isvalid = $this->validateUpdate($request);
        if ($isvalid !== true) {
            return $isvalid;
        }

        // Update the activity itself
        try {
            $activity->update(
                $request->only([
                    'summary',
                    'date_it_happened',
                    'activity_type_id',
                    'description',
                ])
            );
        } catch (QueryException $e) {
            return $this->respondNotTheRightParameters();
        }

        // Log a journal entry but need to delete the previous one first
        $activity->deleteJournalEntry();
        JournalEntry::add($activity);

        // Get the attendees
        $attendeesID = $request->get('contacts');

        // Find existing contacts
        $existing = $activity->contacts()->get();

        foreach ($existing as $contact) {
            // Has an existing attendee been removed?
            if (! in_array($contact->id, $attendeesID)) {
                $contact->activities()->detach($activity);
            }

            // Remove this ID from our list of contacts as we don't
            // want to add them to the activity again
            $idx = array_search($contact->id, $attendeesID);
            unset($attendeesID[$idx]);

            $contact->calculateActivitiesStatistics();
        }

        // New attendees
        foreach ($attendeesID as $attendeeID) {
            $contact = Contact::where('account_id', auth()->user()->account_id)
                ->findOrFail($attendeeID);
            $contact->activities()->attach($activity, [
                'account_id' => auth()->user()->account_id,
            ]);
        }

        return new ActivityResource($activity);
    }

    /**
     * Validate the request for update.
     *
     * @param  Request $request
     * @return \Illuminate\Http\JsonResponse|true
     */
    private function validateUpdate(Request $request)
    {
        // Validates basic fields to create the entry
        $validator = Validator::make($request->all(), [
            'summary' => 'required|max:100000',
            'description' => 'required|max:1000000',
            'date_it_happened' => 'required|date',
            'activity_type_id' => 'integer',
            'contacts' => 'required|array',
        ]);

        if ($validator->fails()) {
            return $this->respondValidatorFailed($validator);
        }

        // Make sure each contact exists and has the right to be associated with
        // this account
        $attendeesID = $request->get('contacts');
        foreach ($attendeesID as $attendeeID) {
            try {
                Contact::where('account_id', auth()->user()->account_id)
                    ->findOrFail($attendeeID);
            } catch (ModelNotFoundException $e) {
                return $this->respondNotFound();
            }
        }

        // Make sure the activity type has the right to be associated with
        // this account
        if ($request->get('activity_type_id')) {
            try {
                ActivityType::where('account_id', auth()->user()->account_id)
                    ->findOrFail($request->get('activity_type_id'));
            } catch (ModelNotFoundException $e) {
                return $this->respondNotFound();
            }
        }

        return true;
    }

    /**
     * Delete an activity.
     *
     * @param Request $request
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy(Request $request, $activityId)
    {
        try {
            $activity = Activity::where('account_id', auth()->user()->account_id)
                ->findOrFail($activityId);
        } catch (ModelNotFoundException $e) {
            return $this->respondNotFound();
        }

        $activity->deleteJournalEntry();

        $activity->delete();

        return $this->respondObjectDeleted($activity->id);
    }

    /**
     * Get the list of activities for the given contact.
     *
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection|\Illuminate\Http\JsonResponse
     */
    public function activities(Request $request, $contactId)
    {
        try {
            $contact = Contact::where('account_id', auth()->user()->account_id)
                ->findOrFail($contactId);
        } catch (ModelNotFoundException $e) {
            return $this->respondNotFound();
        }

        try {
            $activities = $contact->activities()
                ->orderBy($this->sort, $this->sortDirection)
                ->paginate($this->getLimitPerPage());
        } catch (QueryException $e) {
            return $this->respondInvalidQuery();
        }

        return ActivityResource::collection($activities)->additional(['meta' => [
            'statistics' => auth()->user()->account->getYearlyActivitiesStatistics(),
        ]]);
    }
}
