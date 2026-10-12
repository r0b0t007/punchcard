/**
 * When a new version of the app may reload a tab (CHW-35): only the tab
 * whose Reload was tapped. A new service worker takes over every tab at
 * once, but another tab may hold a half-filled form: it keeps its page and
 * its toast, and its own Reload then only reloads it.
 */
export type UpdateFlow = {
    /** Reload was tapped in this tab. */
    requestReload: () => void;
    /** The new version took over, asked for by this tab or another one. */
    newVersionControls: () => void;
};

export function createUpdateFlow(
    reload: () => void,
    activateWaiting: () => void,
): UpdateFlow {
    let requested = false;
    let activated = false;

    return {
        requestReload() {
            if (activated) {
                reload();

                return;
            }

            requested = true;
            activateWaiting();
        },
        newVersionControls() {
            activated = true;

            if (requested) {
                reload();
            }
        },
    };
}
