import '@sulu/web';
import { supportCommandButtons } from './commandButtons';
import { preventDoubleSubmit } from './preventDoubleSubmit';
import { warnOnUnsavedChanges } from './unsavedChanges';
import { smashLogout } from './smashLogout';
import { rollAwayAfterLoad, rollInOnLogin } from './tatamiLogin';

preventDoubleSubmit();
supportCommandButtons();
warnOnUnsavedChanges();
smashLogout();
rollInOnLogin();
rollAwayAfterLoad();
