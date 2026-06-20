function checkAdminSession(req, res, next) {
  if (req.session && req.session.admin) {
    next();
  } else {
    res.redirect('/index');
  }
}

function checkCustomerSession(req, res, next) {
  if (req.session && req.session.customer) {
    next();
  } else {
    res.redirect('/index');
  }
}

module.exports = {
  checkAdminSession,
  checkCustomerSession
};
