import '@sulu/web';
import { showDialogsOnLoad, supportCommandButtons } from './commandButtons';
import { preventDoubleSubmit } from './preventDoubleSubmit';
import { warnOnUnsavedChanges } from './unsavedChanges';
import { smashLogout } from './smashLogout';
import { rollAwayAfterLoad, rollInOnLogin } from './tatamiLogin';

preventDoubleSubmit();
supportCommandButtons();
showDialogsOnLoad();
warnOnUnsavedChanges();
smashLogout();
rollInOnLogin();
rollAwayAfterLoad();
