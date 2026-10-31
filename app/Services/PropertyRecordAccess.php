<?php

namespace App\Services;

use App\Models\PropertyDiscovery;
use App\Models\User;
use App\Models\Valuation;

/**
 * Single authority on "may this user see this property record".
 *
 * The Property View and the Property Record export must never disagree about who
 * may see a record. Left as two private controller methods, they drift: the
 * supervisor scope change (own → team_view) had to be applied twice by hand, and
 * a third copy inside the export endpoint would have been a third chance to forget.
 * So the rules live here once, and every caller — the discovery screen, the
 * valuation screen and the exporter — asks this object.
 *
 * Note the deliberate split between *seeing* a record and *changing* it. A
 * supervisor may see everything their staff are working on, which is what these
 * methods grant, but visibility never confers write authority; that is enforced
 * separately by the write guards on the mutating endpoints.
 */
class PropertyRecordAccess
{
    /**
     * May the user open this discovery in the Property View?
     *
     * Mirrors the rule the discovery screen has always used, with one correction:
     * SCOPE_TEAM_VIEW is treated as a team scope here, matching the valuation
     * side. Today every supervisory role also holds discovery.review and so
     * already passes on the permission branch, so this is a no-op for the seeded
     * roles — it exists so that a future view-only supervisor role cannot end up
     * able to see valuations for their staff but not the discoveries behind them.
     */
    public function canViewDiscovery(PropertyDiscovery $discovery, User $user): bool
    {
        if (in_array($user->scopeLevel(), ['section', 'division', 'system'], true)) {
            return true;
        }

        if ($user->hasAnyPermission(['discovery.review', 'discovery.aarm_review', 'discovery.route_to_valuation'])) {
            return true;
        }

        if ($discovery->discovered_by === $user->id || $discovery->current_owner_id === $user->id) {
            return true;
        }

        if (in_array($user->scopeLevel(), [User::SCOPE_TEAM, User::SCOPE_TEAM_VIEW], true) && $discovery->discovered_by) {
            return User::where('id', $discovery->discovered_by)->where('supervisor_id', $user->id)->exists();
        }

        if ($discovery->valuation_id && Valuation::where('id', $discovery->valuation_id)
            ->where('valuation_officer_id', $user->id)->exists()) {
            return true;
        }

        return false;
    }

    /**
     * May the user open this valuation in the Property View?
     *
     * A section-scoped Account & Records user sees the valuations of their own
     * section plus the ones that have progressed far enough to have become an
     * A&R record (approved, sent to account, registered, complete) — an assessment
     * still in progress is another section's work in progress and is not theirs to
     * read.
     */
    public function canViewValuation(Valuation $valuation, User $user): bool
    {
        $scope = $user->scopeLevel();

        if (in_array($scope, ['system', 'division'], true)) {
            return true;
        }

        if ($scope === 'section') {
            $sectionUserIds = User::where('section_id', $user->section_id)->pluck('id')->all();

            if ($user->section?->code === 'ACCT') {
                return in_array($valuation->status, [
                    Valuation::STATUS_AC_APPROVED,
                    Valuation::STATUS_SENT_TO_ACCT,
                    Valuation::STATUS_REGISTERED,
                    Valuation::STATUS_COMPLETED,
                ], true) || in_array($valuation->valuation_officer_id, $sectionUserIds);
            }

            return in_array($valuation->valuation_officer_id, $sectionUserIds);
        }

        if (in_array($scope, [User::SCOPE_TEAM, User::SCOPE_TEAM_VIEW], true)) {
            return $valuation->valuation_officer_id === $user->id
                || in_array($valuation->valuation_officer_id, User::where('supervisor_id', $user->id)->pluck('id')->all());
        }

        return $valuation->valuation_officer_id == $user->id;
    }

    /**
     * May the user take a copy of a property record out of the system as a
     * printed sheet or a PDF?
     *
     * This is a separate decision from seeing the record. A Property Record
     * carries the property's photographs, TIN, GPS fix and value figures in a form
     * that looks official and travels easily, so it gets its own permission
     * rather than being inherited from whatever let the user open the screen.
     */
    public function canExport(User $user): bool
    {
        return $user->canPermission('property.export');
    }
}
