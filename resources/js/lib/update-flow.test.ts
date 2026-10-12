import { describe, expect, it, vi } from 'vite-plus/test';
import { createUpdateFlow } from '@/lib/update-flow';

function flow() {
    const reload = vi.fn();
    const activateWaiting = vi.fn();

    return {
        reload,
        activateWaiting,
        ...createUpdateFlow(reload, activateWaiting),
    };
}

describe('createUpdateFlow', () => {
    it('reloads the tab whose Reload was tapped, once the new version controls it', () => {
        const tab = flow();

        tab.requestReload();
        expect(tab.activateWaiting).toHaveBeenCalledOnce();
        expect(tab.reload).not.toHaveBeenCalled();

        tab.newVersionControls();
        expect(tab.reload).toHaveBeenCalledOnce();
    });

    it('leaves another tab as it is when the new version takes over', () => {
        const other = flow();

        other.newVersionControls();

        expect(other.reload).not.toHaveBeenCalled();
    });

    it('reloads that other tab when its own Reload is tapped later', () => {
        const other = flow();
        other.newVersionControls();

        other.requestReload();

        expect(other.activateWaiting).not.toHaveBeenCalled();
        expect(other.reload).toHaveBeenCalledOnce();
    });
});
