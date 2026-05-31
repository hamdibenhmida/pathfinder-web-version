// Application Eligibility Checker - Validates candidate profile completeness for job applications

/**
 * Checks profile completeness and enables/disables apply button
 * @param {Object} profileData - Object containing hasResume, skillsCount, hasBio
 */
function checkCompleteness(profileData) {
    let score = 0;
    if (profileData.hasResume) score += 40;  // Resume contributes 40% to completeness
    if (profileData.skillsCount > 3) score += 40;  // Skills > 3 contributes 40%
    if (profileData.hasBio) score += 20;  // Bio contributes 20%

    const btn = document.getElementById('applyBtn');
    // Constraint from UML: Must be > 80% to apply
    if (score >= 80) {
        btn.disabled = false;
        btn.innerText = "Apply Now (" + score + "%)";
    } else {
        btn.disabled = true;
        btn.innerText = "Complete Profile First (" + score + "%)";
    }
}